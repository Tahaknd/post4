<?php

namespace App\Infrastructure\Kafka\Identity;

use Psr\Log\LoggerInterface;

/**
 * The loop of `identity:consume`: reads the events topic as consumer group `identity`, hands every candidate to the
 * IdentityMessageProcessor, produces what came out of it and commits.
 *
 * Delivery is AT LEAST ONCE and every step after the resolver tolerates repeats:
 *   1. a message that cannot be an identity event (the cheap check) is skipped; its offset is committed lazily, in
 *      batches (a replayed skip costs nothing);
 *   2. any other message is processed (Postgres commits inside the resolver), its rows go to the `person_distinct_ids`
 *      topic (or the event to `events_dlq`), the producer is flushed and ONLY THEN is the offset committed, at once.
 *      A crash anywhere in between replays the event: the resolver reports "same person" and the processor re-sends the
 *      current rows of that person for the first event of a partition after an assignment (see the processor).
 *   3. retryable failures (the processor's RetryableFailure, a failed produce, a failed commit that is not a lost
 *      partition) repeat THAT step with exponential backoff (base, doubling, capped) and never commit past the message.
 *      The resolver is not run again after a produce failure: its result is kept and only the produce is repeated.
 *      After $maxAttempts failures of one step the loop throws ConsumerGaveUp and the supervisor restarts the process.
 *   4. a poison message is dead-lettered and committed, so it never blocks its partition.
 *
 * Stops (all exit 0, after committing what is handled and leaving the group): SIGTERM/SIGINT (requestStop(): the
 * current message is finished first), max messages, idle for N seconds, max runtime, memory above the limit (the
 * supervisor starts a fresh process). A stop requested while waiting to retry leaves the message uncommitted.
 *
 * Nothing in the logs comes from a payload: no events, no distinct ids, no error messages of the database (those
 * carry the bound values); only counts, offsets and error classes. The DLQ message carries the details.
 */
final class IdentityConsumer
{
    public const STOP_SIGNAL = 'signal';

    public const STOP_MAX_MESSAGES = 'max_messages';

    public const STOP_IDLE = 'idle';

    public const STOP_MAX_RUNTIME = 'max_runtime';

    public const STOP_MEMORY = 'memory';

    /** Offsets of skipped messages are committed when this many are waiting, or this many seconds passed, or the topic is idle. */
    private const LAZY_COMMIT_MESSAGES = 500;

    private const LAZY_COMMIT_SECONDS = 5.0;

    private bool $stopRequested = false;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<int, int> partition => next offset to commit, for messages not committed yet */
    private array $dirty = [];

    /** @var array<int, true> partitions whose next message may be a replay (see the processor) */
    private array $replayCandidates = [];

    private bool $assigned = false;

    private float $startedAt;

    private float $lastActivityAt;

    private float $lastCommitAt;

    private float $lastSummaryAt;

    private int $consumedAtLastLazyCommit = 0;

    private ?string $stopReason = null;

    /**
     * @param  ?int  $maxMessages  Stop after consuming this many messages (skipped ones count).
     * @param  ?float  $stopAfterIdleSeconds  Stop when no message arrived for this long (counted from the first assignment).
     * @param  ?float  $maxRuntimeSeconds  Stop after this long, assigned or not.
     * @param  \Closure(): float  $clock  Monotonic seconds.
     * @param  \Closure(float): void  $sleeper  Waits that many seconds (the production one wakes early on a stop request).
     * @param  \Closure(): int  $memoryBytes  Current memory use.
     * @param  \Closure(): string  $timestamp  `Y-m-d H:i:s.v` UTC, for the DLQ messages.
     */
    public function __construct(
        private readonly MessageSource $source,
        private readonly RowWriter $writer,
        private readonly MessageProcessor $processor,
        private readonly LoggerInterface $log,
        private readonly string $rowsTopic,
        private readonly string $dlqTopic,
        private readonly ?int $maxMessages = null,
        private readonly ?float $stopAfterIdleSeconds = null,
        private readonly ?float $maxRuntimeSeconds = null,
        private readonly int $maxMemoryBytes = 192 * 1024 * 1024,
        private readonly float $summaryIntervalSeconds = 30.0,
        private readonly int $pollTimeoutMs = 1000,
        private readonly int $maxAttempts = 12,
        private readonly float $backoffBaseSeconds = 0.5,
        private readonly float $backoffMaxSeconds = 30.0,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $sleeper = null,
        private readonly ?\Closure $memoryBytes = null,
        private readonly ?\Closure $timestamp = null,
    ) {}

    /** Asks the loop to stop after the message it is working on (signal handlers call this). */
    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    /**
     * @return string why it stopped (one of the STOP_ constants)
     *
     * @throws ConsumerGaveUp
     */
    public function run(): string
    {
        $this->startedAt = $this->lastActivityAt = $this->lastCommitAt = $this->lastSummaryAt = $this->mono();
        $this->source->listen($this->onAssigned(...), $this->onRevoked(...));

        try {
            while (($reason = $this->shouldStop()) === null) {
                $message = $this->source->poll($this->pollTimeoutMs);

                if ($message === null) {
                    $this->flushLazyCommits(force: true);
                    $this->summaryIfDue();

                    continue;
                }

                try {
                    $this->handle($message);
                } catch (StopWhileWaiting) {
                    break;
                }

                // Idle is the time since the last message was FINISHED (retries can take minutes).
                $this->lastActivityAt = $this->mono();

                $this->flushLazyCommits();
                $this->summaryIfDue();
            }
        } finally {
            $this->shutdown();
        }

        $this->summary('stopped');

        return $this->stopReason = $reason ?? self::STOP_SIGNAL;
    }

    private function shouldStop(): ?string
    {
        $now = $this->mono();

        return match (true) {
            $this->stopRequested => self::STOP_SIGNAL,
            $this->maxMessages !== null && ($this->counts['consumed'] ?? 0) >= $this->maxMessages => self::STOP_MAX_MESSAGES,
            $this->maxRuntimeSeconds !== null && $now - $this->startedAt >= $this->maxRuntimeSeconds => self::STOP_MAX_RUNTIME,
            $this->assigned && $this->stopAfterIdleSeconds !== null && $now - $this->lastActivityAt >= $this->stopAfterIdleSeconds => self::STOP_IDLE,
            $this->memoryNow() > $this->maxMemoryBytes => self::STOP_MEMORY,
            default => null,
        };
    }

    private function handle(ConsumedMessage $message): void
    {
        $this->count('consumed');

        $reconcile = isset($this->replayCandidates[$message->partition]);

        $processed = $this->retrying('resolve', fn () => $this->processor->process($message, $reconcile));

        $this->count($processed->disposition->value);

        if (! $processed->disposition->commitsAtOnce()) {
            $this->dirty[$message->partition] = $message->offset + 1;

            return;
        }

        // The first event that reached the resolver after the assignment has been judged: later ones cannot be replays.
        unset($this->replayCandidates[$message->partition]);

        if ($processed->disposition === Disposition::Poison) {
            $this->deadLetter($message, $processed);
        } elseif ($processed->rows !== []) {
            $messages = array_map(PersonDistinctIdRow::message(...), $processed->rows);
            $this->retrying('produce', fn () => $this->writer->publish($this->rowsTopic, $messages));
            $this->count('rows_produced', count($messages));
        }

        $this->dirty[$message->partition] = $message->offset + 1;
        $this->commitNow();
    }

    private function deadLetter(ConsumedMessage $message, Processed $processed): void
    {
        $envelope = DeadLetter::message($message, (string) $processed->errorClass, (string) $processed->errorMessage, $this->stamp());

        $this->retrying('dead letter', fn () => $this->writer->publish($this->dlqTopic, [$envelope]));

        $this->log->warning('identity:consume dead-lettered an event', [
            'topic' => $message->topic,
            'partition' => $message->partition,
            'offset' => $message->offset,
            'error_class' => $processed->errorClass,
        ]);
    }

    /** Commits every waiting offset now; a failure that is not a lost partition is retried like any other step. */
    private function commitNow(): void
    {
        if ($this->dirty === []) {
            return;
        }

        try {
            $this->retrying('commit', fn () => $this->commitDirty());
        } catch (CommitFailed) {
            // Ownership lost: the new owner replays from the last committed offset and re-sends what is needed.
        }
    }

    /** @throws CommitFailed */
    private function commitDirty(): void
    {
        if ($this->dirty === []) {
            return;
        }

        try {
            $this->source->commit($this->dirty);
        } catch (CommitFailed $e) {
            if ($e->ownershipLost) {
                // Those partitions belong to somebody else now; what is waiting must not be committed again.
                $this->dirty = [];
                $this->count('commits_lost');
                $this->log->warning('identity:consume lost its partitions while committing; the new owner replays them');
            }

            throw $e;
        }

        $this->dirty = [];
        $this->lastCommitAt = $this->mono();
        $this->count('commits');
    }

    private function flushLazyCommits(bool $force = false): void
    {
        if ($this->dirty === []) {
            return;
        }

        $consumed = $this->counts['consumed'] ?? 0;

        if (! $force
            && $consumed - $this->consumedAtLastLazyCommit < self::LAZY_COMMIT_MESSAGES
            && $this->mono() - $this->lastCommitAt < self::LAZY_COMMIT_SECONDS) {
            return;
        }

        $this->consumedAtLastLazyCommit = $consumed;

        try {
            $this->commitDirty();
        } catch (CommitFailed) {
            // Skipped messages only: the next flush tries again (a replayed skip costs nothing).
        }
    }

    /**
     * Runs one step; a retryable failure repeats it after a backoff, up to the attempt budget.
     *
     * @template T
     *
     * @param  \Closure(): T  $step
     * @return T
     *
     * @throws StopWhileWaiting
     * @throws ConsumerGaveUp
     * @throws CommitFailed when the partition was lost (never retried)
     */
    private function retrying(string $name, \Closure $step): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $step();
            } catch (CommitFailed $e) {
                if ($e->ownershipLost) {
                    throw $e;
                }
                $failure = $e;
            } catch (RetryableFailure|WriteFailed $e) {
                $failure = $e;
            }

            $this->count('retries');

            if ($attempt >= $this->maxAttempts) {
                $this->log->error('identity:consume gave up', ['step' => $name, 'attempts' => $attempt, 'error_class' => class_basename($failure)]);

                throw new ConsumerGaveUp("The step '{$name}' failed {$attempt} times in a row.", 0, $failure);
            }

            $delay = min($this->backoffMaxSeconds, $this->backoffBaseSeconds * (2 ** ($attempt - 1)));
            $this->log->warning('identity:consume retrying', ['step' => $name, 'attempt' => $attempt, 'delay_ms' => (int) round($delay * 1000), 'error_class' => class_basename($failure)]);

            $this->wait($delay);
        }
    }

    /** @throws StopWhileWaiting */
    private function wait(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);
        } else {
            // Slices of 100 ms, so a stop request is noticed at once.
            for ($waited = 0.0; $waited < $seconds && ! $this->stopRequested; $waited += 0.1) {
                usleep((int) (min(0.1, $seconds - $waited) * 1_000_000));
            }
        }

        if ($this->stopRequested) {
            throw new StopWhileWaiting;
        }
    }

    /** @param list<int> $partitions */
    private function onAssigned(array $partitions): void
    {
        $this->assigned = true;
        $this->lastActivityAt = $this->mono();

        foreach ($partitions as $partition) {
            $this->replayCandidates[$partition] = true;
        }
    }

    /** @param list<int> $partitions */
    private function onRevoked(array $partitions): void
    {
        // The last chance to commit what these partitions handled (a failure only means the next owner replays it).
        if ($this->dirty !== []) {
            try {
                $this->commitDirty();
            } catch (CommitFailed) {
            }
        }

        foreach ($partitions as $partition) {
            unset($this->replayCandidates[$partition], $this->dirty[$partition]);
        }
    }

    private function shutdown(): void
    {
        try {
            $this->commitDirty();
        } catch (\Throwable) {
            // Nothing handled is lost: it is replayed.
        }

        try {
            $this->source->close();
        } catch (\Throwable) {
        }
    }

    private function summaryIfDue(): void
    {
        if ($this->mono() - $this->lastSummaryAt >= $this->summaryIntervalSeconds) {
            $this->summary('running');
        }
    }

    private function summary(string $state): void
    {
        $this->lastSummaryAt = $this->mono();
        $counts = $this->counts;

        $this->log->info('identity:consume', [
            'state' => $state,
            'uptime_seconds' => (int) round($this->lastSummaryAt - $this->startedAt),
            'memory_mb' => (int) round($this->memoryNow() / 1048576),
            ...$counts,
        ]);
    }

    private function count(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    private function memoryNow(): int
    {
        return $this->memoryBytes !== null ? ($this->memoryBytes)() : memory_get_usage(true);
    }

    private function mono(): float
    {
        return $this->clock !== null ? ($this->clock)() : hrtime(true) / 1e9;
    }

    private function stamp(): string
    {
        return $this->timestamp !== null ? ($this->timestamp)() : gmdate('Y-m-d H:i:s').'.'.sprintf('%03d', (int) (fmod(microtime(true), 1) * 1000));
    }
}
