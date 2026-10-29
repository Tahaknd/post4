<?php

namespace App\Infrastructure\Kafka\Identity;

use App\Domain\Identity\DistinctIdChange;
use App\Domain\Identity\IdentityEvent;
use App\Domain\Identity\IdentityKind;
use App\Domain\Identity\IdentityNoopReason;
use App\Domain\Identity\IdentityOutcome;
use App\Domain\Identity\IdentityResolver;
use App\Domain\Identity\StaleIdentityState;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Turns one message of the events topic into what the identity consumer has to do: nothing, the rows to produce, or a
 * reason to dead-letter it. It reads no Kafka and writes no Kafka; the only side effect is IdentityResolver::apply().
 *
 * Failures of the resolver are sorted into three kinds:
 *   retryable   a lock timeout (55P03), a deadlock or serialization failure (40xxx), a lost or refused connection (08xxx,
 *               57xxx, 53xxx, what Laravel recognises as a lost connection) and StaleIdentityState after the resolver's
 *               own attempts. Thrown as RetryableFailure; the database connection is dropped first so the next attempt
 *               starts on a fresh one. The caller retries WITHOUT committing.
 *   project gone a foreign key violation (23503) while the project no longer exists: the event outlived its project.
 *               Skipped and counted, not dead-lettered (deleting a project would otherwise fill the DLQ).
 *   permanent   everything else (a data exception, a bug): the event goes to the DLQ with the error class and message.
 *
 * Replay after a crash. The consumer commits an offset only after the rows reached Kafka, so a crash between the
 * Postgres commit and the produce replays the event, and the resolver then reports "same person": nothing changed, no
 * rows. When $reconcile is true (the first event of a partition after an assignment: the only one that can be such a
 * replay, because every event that reaches the resolver is committed before the next one is read) a SamePerson no-op
 * therefore re-sends the CURRENT rows of the person (IdentityResolver::currentRows). Harmless when nothing was lost: the
 * same rows with the same versions.
 */
final class IdentityMessageProcessor implements MessageProcessor
{
    /** @var list<string> What the cheap check looks for: the three event names of IdentityKind. */
    private const NAMES = ['$identify', '$create_alias', '$merge_dangerously'];

    /**
     * @param  \Closure(): void  $resetConnection  Drops the database connection after a retryable failure.
     * @param  \Closure(): CarbonImmutable  $now  Fallback for the created_at of a row whose event has no timestamp.
     */
    public function __construct(
        private readonly IdentityResolver $resolver,
        private readonly \Closure $resetConnection,
        private readonly \Closure $now,
    ) {}

    /**
     * The cheap check: does the raw message contain the name of an identity event at all? A superset by construction
     * (the event name is a JSON string of plain ASCII that the producer never escapes, and `properties` is a string
     * inside the message, so its quotes are escaped and cannot fake the name of the event), so a false result is
     * final and a true one is only a candidate that process() decodes and checks.
     */
    public static function isCandidate(string $payload): bool
    {
        foreach (self::NAMES as $name) {
            if (str_contains($payload, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @throws RetryableFailure */
    public function process(ConsumedMessage $message, bool $reconcile = false): Processed
    {
        if (! self::isCandidate($message->payload)) {
            return new Processed(Disposition::NotIdentity);
        }

        try {
            $decoded = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->poison('JsonException', $e->getMessage());
        }

        if (! is_array($decoded)) {
            return $this->poison('InvalidEvent', 'the message is not a JSON object');
        }

        $name = $decoded['event'] ?? null;

        if (! is_string($name) || IdentityKind::fromEventName($name) === null) {
            return new Processed(Disposition::NotIdentity);
        }

        $distinctId = $decoded['distinct_id'] ?? null;
        $projectId = $decoded['project_id'] ?? null;

        if (! is_string($distinctId) || $distinctId === '' || ! is_int($projectId) || $projectId <= 0) {
            return $this->poison('InvalidEvent', 'an identity event needs a string distinct_id and a positive integer project_id');
        }

        $event = IdentityEvent::fromNormalized($decoded);

        if ($event === null) {
            return new Processed(Disposition::NoCounterpart);
        }

        try {
            return $this->apply($event, $decoded, $reconcile);
        } catch (Throwable $e) {
            return $this->failed($e, $projectId);
        }
    }

    /** @param array<string, mixed> $decoded */
    private function apply(IdentityEvent $event, array $decoded, bool $reconcile): Processed
    {
        $result = $this->resolver->apply($event);
        $createdAt = $this->createdAt($decoded);

        if ($result->outcome === IdentityOutcome::Rejected) {
            return new Processed(Disposition::Rejected);
        }

        if ($result->outcome === IdentityOutcome::Noop) {
            if ($reconcile && $result->reason === IdentityNoopReason::SamePerson) {
                return new Processed(Disposition::Reconciled, $this->rows($this->resolver->currentRows($event), $createdAt));
            }

            return new Processed(Disposition::Noop);
        }

        $disposition = match ($result->outcome) {
            IdentityOutcome::Created => Disposition::Created,
            IdentityOutcome::Attached => Disposition::Attached,
            default => Disposition::Merged,
        };

        return new Processed($disposition, $this->rows($result->changes, $createdAt));
    }

    /**
     * @param  list<DistinctIdChange>  $changes
     * @return list<array<string, mixed>>
     */
    private function rows(array $changes, string $createdAt): array
    {
        return array_map(fn ($change) => PersonDistinctIdRow::fromChange($change, $createdAt), $changes);
    }

    /** @param array<string, mixed> $decoded */
    private function createdAt(array $decoded): string
    {
        $timestamp = $decoded['timestamp'] ?? null;

        if (is_string($timestamp) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $timestamp) === 1) {
            return $timestamp;
        }

        return ($this->now)()->utc()->format('Y-m-d H:i:s.v');
    }

    /** @throws RetryableFailure */
    private function failed(Throwable $e, int $projectId): Processed
    {
        if ($e instanceof StaleIdentityState || self::isRetryable($e)) {
            ($this->resetConnection)();

            throw new RetryableFailure(class_basename($e).': '.self::sqlState($e), $e);
        }

        if (self::sqlState($e) === '23503') {
            try {
                if (! Project::query()->whereKey($projectId)->exists()) {
                    return new Processed(Disposition::ProjectGone);
                }
            } catch (Throwable $lookup) {
                ($this->resetConnection)();

                throw new RetryableFailure(class_basename($lookup).': '.self::sqlState($lookup), $lookup);
            }
        }

        return $this->poison(get_class($e), $e->getMessage());
    }

    private function poison(string $class, string $message): Processed
    {
        return new Processed(Disposition::Poison, errorClass: $class, errorMessage: $message);
    }

    /** The SQLSTATE of a database exception, anywhere in the chain of previous exceptions; '' when there is none. */
    public static function sqlState(Throwable $e): string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof QueryException && isset($current->errorInfo[0]) && is_string($current->errorInfo[0])) {
                return $current->errorInfo[0];
            }

            if ($current instanceof PDOException && isset($current->errorInfo[0]) && is_string($current->errorInfo[0])) {
                return $current->errorInfo[0];
            }
        }

        return '';
    }

    public static function isRetryable(Throwable $e): bool
    {
        $state = self::sqlState($e);

        // 08 connection exception, 40 rollback (serialization failure 40001, deadlock 40P01), 53 insufficient resources
        // (too many connections), 55P03 lock not available (the resolver's lock_timeout), 57 operator intervention
        // (administrator shutdown, cannot connect now), 58 system error.
        if ($state !== '' && (in_array(substr($state, 0, 2), ['08', '40', '53', '57', '58'], true) || $state === '55P03')) {
            return true;
        }

        $lost = new LostConnectionDetector;

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException || $current instanceof QueryException || $current instanceof LostConnectionException) {
                if ($lost->causedByLostConnection($current)
                    || preg_match('/could not (connect|translate host name|receive data|send data)|connection (refused|timed out)|Connection reset/i', $current->getMessage()) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
