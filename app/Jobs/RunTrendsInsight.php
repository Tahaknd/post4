<?php

namespace App\Jobs;

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Domain\Insights\Trends\InvalidTrendsQuery;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsResult;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Runs ONE trends insight for ONE project on the `insights` queue and stores the outcome in the run record
 * ({@see InsightRunStore}), where the poll endpoint reads it.
 *
 * Payload. Only ids, the dispatch slot indexes and the canonical definition array: no object, no key, no secret.
 * The definition is rebuilt with TrendsQuery::fromCanonical, i.e. validated again with every constructor rule. The
 * slot indexes (project and user dispatch slots, owned by the run id) are the AUTHORITATIVE copy: the job gives them
 * back on EVERY exit (done, failed, cancelled, skipped, record evicted, project deleted, killed through failed()).
 * The copy in the run record is informational (the controller frees them from it when a run is cancelled).
 *
 * Never rethrows a query failure. An InsightFailed (ClickHouse refused, timed out, was busy ...) is stored as a
 * `failed` record carrying the ClickHouseErrorKind, and the job ends normally: `failed_jobs` (and Horizon) never
 * hold raw ClickHouse text. An unexpected exception is stored as kind `unknown`, reported with ids and the
 * exception CLASS only, and not rethrown either. Only an infrastructure failure of the run store itself (the cache
 * is down while writing the outcome) propagates, which fails the job once ($maxExceptions = 1).
 *
 * Run state is checked, never assumed. The record is read at pop time and again after the single-flight lock is
 * taken, and the transition to `running` is a compare-and-set: a cancelled, finished or evicted run never queries.
 * A `running` record found with attempts() > 1 is a TAKEOVER: the previous attempt died (killed, worker lost) and
 * its locks have expired by now (lock TTL 50 s < retry_after 90 s), so the job takes the gates again and runs. With
 * attempts() == 1 a `running` record is a duplicate delivery of a live attempt and is skipped.
 *
 * Tries. Queries are never retried automatically: a failed query stays failed ($maxExceptions = 1). The high
 * $tries exists only because release() counts as an attempt, and the job releases itself deliberately:
 *  - ClickHouse answered Busy (202): release(3), at most BUSY_RETRIES times, then it fails with kind `busy`;
 *  - another run of the SAME definition holds the single-flight lock: release(1) and look at the shared result
 *    cache (or the holder's failure marker) again next time; the job never sleeps: only two workers serve this queue;
 *  - the project already runs its maximum of concurrent queries: release(3), run stays `queued`.
 * Waiting is bounded by maxAttempts() pops: lock TTL (50 s) at one release a second, plus a margin of 10. A dead
 * holder's lock has expired by then, so waiting past it means the holder is alive and slow: the run fails with `busy`.
 *
 * Timing. $timeout = 45: above the read-only profile's max_execution_time (20 s, constraint cap 22 s), below the
 * lock TTL (50 s) and the Redis queue's retry_after (90 s), so a run is never handed to a second worker while it
 * still runs. An HTTP attempt can last 25 s and a connection failure is retried twice (client select_retries); only
 * a connection that hangs on every attempt can reach the 45 s kill, and then failed() stores kind `timeout`.
 * $failOnTimeout marks a killed job failed.
 *
 * Single flight: of several runs of the same (project, definition) the one holding
 * `insights:flight:trends:{project}:{hash}` queries ClickHouse, the others copy its result from the shared cache
 * (InsightRunner caches successful results for `cache_ttl_seconds`) or its failure from a short marker. With the
 * cache switched off successful waiters simply run one after another.
 */
class RunTrendsInsight implements ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'insights';

    /** Releases caused by a busy ClickHouse (kind Busy) before the run fails with it. */
    public const int BUSY_RETRIES = 3;

    public const int BUSY_RELEASE_DELAY_SECONDS = 3;

    public const int WAIT_RELEASE_DELAY_SECONDS = 1;

    /** Pops of one run beyond the lock TTL that waiting may take. */
    private const int WAIT_MARGIN_ATTEMPTS = 10;

    /** Releases count as attempts, see the class comment; above any maxAttempts(). Real failures are bounded by $maxExceptions. */
    public int $tries = 200;

    public int $maxExceptions = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    private ?string $hash = null;

    /** @param  array<array-key, mixed>  $canonical  TrendsQuery::canonical() */
    public function __construct(
        public readonly int $projectId,
        public readonly string $runId,
        public readonly array $canonical,
        public readonly int $userId,
        public readonly ?int $projectSlot = null,
        public readonly ?int $userSlot = null,
    ) {
        if ($projectId < 1 || $userId < 1 || ! InsightRunStore::isValidId($runId)) {
            throw new InvalidArgumentException('An insight run needs positive project and user ids and a valid run id.');
        }

        $this->onQueue(self::QUEUE);
    }

    /** Pops of one run before waiting turns into a `busy` failure: (lock TTL / release delay) + margin. */
    public static function maxAttempts(): int
    {
        return (int) ceil(max(1, (int) config('insights.lock_ttl_seconds')) / self::WAIT_RELEASE_DELAY_SECONDS) + self::WAIT_MARGIN_ATTEMPTS;
    }

    /** @return list<string> Horizon tags: ids only */
    public function tags(): array
    {
        return ['insights', "project:{$this->projectId}"];
    }

    public function handle(InsightRunner $runner, InsightRunStore $runs, InsightGate $gate): void
    {
        $started = microtime(true);
        $record = $runs->find($this->runId);

        // Expired, evicted or foreign: nobody can poll it, nothing to compute. The slots are still ours to free.
        if ($record === null || $record['project_id'] !== $this->projectId || $record['user_id'] !== $this->userId) {
            $this->releaseAll($gate);
            Log::info('insight run skipped', ['project_id' => $this->projectId, 'run_id' => $this->runId, 'reason' => 'expired']);

            return;
        }

        if (InsightRunStore::isTerminal($record['status'])) {
            $this->releaseAll($gate);
            Log::info('insight run skipped', ['project_id' => $this->projectId, 'run_id' => $this->runId, 'reason' => $record['status']]);

            return;
        }

        if ($record['status'] === InsightRunStore::RUNNING) {
            if ($this->attempts() <= 1) {
                // A duplicate delivery of an attempt that is still running: that attempt owns the run and its slots.
                Log::info('insight run skipped', ['project_id' => $this->projectId, 'run_id' => $this->runId, 'reason' => 'running']);

                return;
            }
            Log::warning('insight run taken over after a dead attempt', ['project_id' => $this->projectId, 'run_id' => $this->runId, 'attempt' => $this->attempts()]);
        }

        if (! Project::query()->whereKey($this->projectId)->exists()) {
            $runs->forget($this->runId);
            $this->releaseAll($gate);
            Log::info('insight run skipped', ['project_id' => $this->projectId, 'run_id' => $this->runId, 'reason' => 'project_deleted']);

            return;
        }

        try {
            $query = TrendsQuery::fromCanonical($this->canonical);
        } catch (InvalidTrendsQuery) {
            Log::warning('insight run has an invalid definition', ['project_id' => $this->projectId, 'run_id' => $this->runId]);
            $this->endFailed($runs, $gate, ClickHouseErrorKind::Unknown);

            return;
        }
        $this->hash = $query->hash();

        if ($this->finishFromShared($runner, $runs, $gate, $query, $started)) {
            return;
        }

        $flight = $gate->acquireFlight(InsightRunStore::TYPE_TRENDS, $this->projectId, $this->hash, $this->runId);
        if ($flight === null) {
            // Another run of the same definition is querying: look at the shared result again next time.
            $this->releaseOrGiveUp($runs, $gate, self::WAIT_RELEASE_DELAY_SECONDS);

            return;
        }

        $permit = null;
        try {
            // Cancelled, finished or evicted while this job waited for the lock: do not query.
            $fresh = $runs->find($this->runId);
            if ($fresh === null || InsightRunStore::isTerminal($fresh['status'])) {
                $this->releaseAll($gate);

                return;
            }

            // The holder may have finished (or failed) between the checks above and taking the lock.
            if ($this->finishFromShared($runner, $runs, $gate, $query, $started, 'shared')) {
                return;
            }

            $permit = $gate->acquireRunning($this->projectId, $this->runId);
            if ($permit === null) {
                $this->releaseOrGiveUp($runs, $gate, self::BUSY_RELEASE_DELAY_SECONDS);

                return;
            }

            if (! $runs->markRunning($this->runId)) {
                // Lost the race with a cancellation.
                $this->releaseAll($gate);

                return;
            }

            try {
                $result = $runner->trends($this->projectId, $query);
            } catch (InsightFailed $e) {
                if ($e->isBusy() && $record['busy_retries'] < self::BUSY_RETRIES && $this->attempts() < self::maxAttempts()) {
                    $runs->markQueued($this->runId, $record['busy_retries'] + 1);
                    $this->release($e->retryAfterSeconds() ?? self::BUSY_RELEASE_DELAY_SECONDS);

                    return;
                }
                if (! in_array($e->kind, [ClickHouseErrorKind::Busy, ClickHouseErrorKind::Unavailable], true)) {
                    $gate->rememberFlightFailure(InsightRunStore::TYPE_TRENDS, $this->projectId, $this->hash, $e->kind);
                }
                $this->endFailed($runs, $gate, $e->kind);

                return;
            } catch (Throwable $e) {
                // Whatever it was, its text may hold query fragments or values: the class and the ids are all that leave here.
                report(new RuntimeException(sprintf('Insight run %s of project %d failed unexpectedly (%s)', $this->runId, $this->projectId, $e::class)));
                $this->endFailed($runs, $gate, ClickHouseErrorKind::Unknown);

                return;
            }

            // Outside the try: a failing run store is an infrastructure problem and propagates (fails the job once).
            $this->done($runs, $gate, $result, 'query', $started);
        } finally {
            $permit?->release();
            $flight->release();
        }
    }

    /**
     * Horizon marks a job that timed out or was handed back too often as failed; the run record must not stay
     * `running`, and the slots must not leak. Never trusts the exception text: only the class decides the kind.
     */
    public function failed(Throwable $e): void
    {
        try {
            $runs = app(InsightRunStore::class);
            $runs->markFailed($this->runId, $e instanceof TimeoutExceededException ? ClickHouseErrorKind::Timeout : ClickHouseErrorKind::Unknown);
        } catch (Throwable) {
            // The run TTL cleans up; a failure handler must not throw.
        }

        $this->releaseAll(app(InsightGate::class));
    }

    /** A result in the shared cache or a failure marker of the holder ends this run without a query. */
    private function finishFromShared(InsightRunner $runner, InsightRunStore $runs, InsightGate $gate, TrendsQuery $query, float $started, string $source = 'cache'): bool
    {
        if (($cached = $runner->cached($this->projectId, $query)) !== null) {
            $this->done($runs, $gate, $cached, $source, $started);

            return true;
        }

        if (($kind = $gate->flightFailure(InsightRunStore::TYPE_TRENDS, $this->projectId, (string) $this->hash)) !== null) {
            $this->endFailed($runs, $gate, $kind, 'shared');

            return true;
        }

        return false;
    }

    private function done(InsightRunStore $runs, InsightGate $gate, TrendsResult $result, string $source, float $started): void
    {
        $runs->markDone($this->runId, $result->toArray());
        $this->releaseAll($gate);
        Log::info('insight run done', [
            'project_id' => $this->projectId,
            'run_id' => $this->runId,
            'source' => $source,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    private function endFailed(InsightRunStore $runs, InsightGate $gate, ClickHouseErrorKind $kind, string $source = 'query'): void
    {
        $runs->markFailed($this->runId, $kind);
        $this->releaseAll($gate);
        Log::info('insight run failed', ['project_id' => $this->projectId, 'run_id' => $this->runId, 'kind' => $kind->value, 'source' => $source]);
    }

    /** Hands the run back to the queue, or ends it with kind `busy` once the waiting budget is spent. */
    private function releaseOrGiveUp(InsightRunStore $runs, InsightGate $gate, int $delaySeconds): void
    {
        if ($this->attempts() >= self::maxAttempts()) {
            $this->endFailed($runs, $gate, ClickHouseErrorKind::Busy);

            return;
        }

        $this->release($delaySeconds);
    }

    /** Both dispatch slots and the inflight key of this run: owner-checked, safe to repeat, never throws. */
    private function releaseAll(InsightGate $gate): void
    {
        $gate->releaseSlots($this->projectId, $this->userId, $this->runId, $this->projectSlot, $this->userSlot);

        $hash = $this->hash;
        if ($hash === null) {
            try {
                $hash = TrendsQuery::fromCanonical($this->canonical)->hash();
            } catch (Throwable) {
                return;
            }
        }
        $gate->releaseInflight($this->projectId, $this->userId, $hash, $this->runId);
    }
}
