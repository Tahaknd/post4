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
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Runs ONE trends insight for ONE project on the `insights` queue and stores the outcome in the run record
 * ({@see InsightRunStore}), where the poll endpoint reads it.
 *
 * Payload. Only (project id, run id, canonical definition array): no object, no user, no key. The definition is
 * rebuilt with TrendsQuery::fromCanonical, i.e. validated again with every constructor rule.
 *
 * Never rethrows a query failure. An InsightFailed (ClickHouse refused, timed out, was busy ...) is stored as a
 * `failed` record carrying the ClickHouseErrorKind, and the job ends normally: `failed_jobs` (and Horizon) never
 * hold raw ClickHouse text. An unexpected exception is stored as kind `unknown`, reported with ids and the
 * exception CLASS only, and not rethrown either. Only an infrastructure failure of the run store itself (the cache
 * is down while writing the outcome) propagates, which fails the job once ($maxExceptions = 1).
 *
 * Tries. Queries are never retried automatically: a failed query stays failed ($maxExceptions = 1). The high
 * $tries exists only because release() counts as an attempt, and the job releases itself deliberately:
 *  - ClickHouse answered Busy (202): release(3), at most BUSY_RETRIES times, then it fails with kind `busy`;
 *  - another run of the SAME definition holds the single-flight lock: release(1) and look at the shared result
 *    cache again next time (the job never sleeps: only two workers serve this queue);
 *  - the project already runs its maximum of concurrent queries: release(3), run stays `queued`.
 * Waiting is bounded by MAX_ATTEMPTS pops in total; beyond it the run fails with kind `busy` (retryable).
 *
 * Timing. $timeout = 45: above the read-only profile's max_execution_time (20 s, constraint cap 22 s, HTTP timeout
 * 25 s), below the Redis queue's retry_after (90 s), so a run is never handed to a second worker while it still
 * runs. $failOnTimeout marks a killed job failed; failed() then stores kind `timeout`.
 *
 * Unique per (project, run) on the insight cache store. Single flight: of several runs of the same
 * (project, definition) the one holding `insights:flight:{project}:{hash}` queries ClickHouse, the others copy
 * its result from the shared cache (InsightRunner caches successful results for `cache_ttl_seconds`). With the cache
 * switched off they simply run one after another.
 */
class RunTrendsInsight implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'insights';

    /** Pops of one run (first attempt + releases) before waiting turns into a `busy` failure. About 25 to 75 s of waiting. */
    public const int MAX_ATTEMPTS = 25;

    /** Releases caused by a busy ClickHouse (kind Busy) before the run fails with it. */
    public const int BUSY_RETRIES = 3;

    public const int BUSY_RELEASE_DELAY_SECONDS = 3;

    public const int WAIT_RELEASE_DELAY_SECONDS = 1;

    /** Releases count as attempts, see the class comment. Real failures are bounded by $maxExceptions. */
    public int $tries = 30;

    public int $maxExceptions = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    /** Seconds the unique lock outlives a job that died without releasing it (the run TTL). */
    public int $uniqueFor = 900;

    /** @param  array<array-key, mixed>  $canonical  TrendsQuery::canonical() */
    public function __construct(
        public readonly int $projectId,
        public readonly string $runId,
        public readonly array $canonical,
    ) {
        if ($projectId < 1 || ! InsightRunStore::isValidId($runId)) {
            throw new InvalidArgumentException('An insight run needs a positive project id and a valid run id.');
        }

        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return "insights-run:{$this->projectId}:{$this->runId}";
    }

    /** The unique lock lives on the insight cache store (Redis), not on whatever CACHE_STORE says. */
    public function uniqueVia(): Cache
    {
        return CacheFacade::store((string) config('insights.cache_store'));
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

        // Expired, foreign, or no longer queued (answered by a duplicate delivery): nothing to do.
        if ($record === null || $record['project_id'] !== $this->projectId || $record['status'] !== InsightRunStore::QUEUED) {
            Log::info('insight run skipped', ['project_id' => $this->projectId, 'run_id' => $this->runId, 'reason' => $record === null ? 'expired' : 'not_queued']);

            return;
        }
        $slot = $record['slot'];

        if (! Project::query()->whereKey($this->projectId)->exists()) {
            $runs->forget($this->runId);
            $gate->releaseDispatchSlot($this->projectId, $this->runId, $slot);
            Log::info('insight run skipped', ['project_id' => $this->projectId, 'run_id' => $this->runId, 'reason' => 'project_deleted']);

            return;
        }

        try {
            $query = TrendsQuery::fromCanonical($this->canonical);
        } catch (InvalidTrendsQuery) {
            Log::warning('insight run has an invalid definition', ['project_id' => $this->projectId, 'run_id' => $this->runId]);
            $this->endFailed($runs, $gate, $slot, ClickHouseErrorKind::Unknown);

            return;
        }

        if (($cached = $runner->cached($this->projectId, $query)) !== null) {
            $this->done($runs, $gate, $slot, $cached, 'cache', $started);

            return;
        }

        $flight = $gate->acquireFlight($this->projectId, $query->hash(), $this->runId);
        if ($flight === null) {
            // Another run of the same definition is querying: look at the shared result again next time.
            $this->releaseOrGiveUp($runs, $gate, $slot, self::WAIT_RELEASE_DELAY_SECONDS);

            return;
        }

        $permit = null;
        try {
            // The holder may have finished between the cache read above and taking the lock.
            if (($cached = $runner->cached($this->projectId, $query)) !== null) {
                $this->done($runs, $gate, $slot, $cached, 'shared', $started);

                return;
            }

            $permit = $gate->acquireRunning($this->projectId, $this->runId);
            if ($permit === null) {
                $this->releaseOrGiveUp($runs, $gate, $slot, self::BUSY_RELEASE_DELAY_SECONDS);

                return;
            }

            $runs->markRunning($this->runId);

            try {
                $result = $runner->trends($this->projectId, $query);
            } catch (InsightFailed $e) {
                if ($e->isBusy() && $record['busy_retries'] < self::BUSY_RETRIES && $this->attempts() < self::MAX_ATTEMPTS) {
                    $runs->markQueued($this->runId, $record['busy_retries'] + 1);
                    $this->release($e->retryAfterSeconds() ?? self::BUSY_RELEASE_DELAY_SECONDS);

                    return;
                }
                $this->endFailed($runs, $gate, $slot, $e->kind);

                return;
            } catch (Throwable $e) {
                // Whatever it was, its text may hold query fragments or values: the class and the ids are all that leave here.
                report(new RuntimeException(sprintf('Insight run %s of project %d failed unexpectedly (%s)', $this->runId, $this->projectId, $e::class)));
                $this->endFailed($runs, $gate, $slot, ClickHouseErrorKind::Unknown);

                return;
            }

            // Outside the try: a failing run store is an infrastructure problem and propagates (fails the job once).
            $this->done($runs, $gate, $slot, $result, 'query', $started);
        } finally {
            $permit?->release();
            $flight->release();
        }
    }

    /**
     * Horizon marks a job that timed out or was handed back too often as failed; the run record must not stay
     * `running`. Never trusts the exception text: only the class decides the kind.
     */
    public function failed(Throwable $e): void
    {
        try {
            $runs = app(InsightRunStore::class);
            $record = $runs->find($this->runId);

            if ($record !== null && $record['project_id'] === $this->projectId && ! in_array($record['status'], [InsightRunStore::DONE, InsightRunStore::FAILED], true)) {
                $runs->markFailed($this->runId, $e instanceof TimeoutExceededException ? ClickHouseErrorKind::Timeout : ClickHouseErrorKind::Unknown);
            }
            app(InsightGate::class)->releaseDispatchSlot($this->projectId, $this->runId, $record['slot'] ?? null);
        } catch (Throwable) {
            // The run TTL cleans up; a failure handler must not throw.
        }
    }

    private function done(InsightRunStore $runs, InsightGate $gate, ?int $slot, TrendsResult $result, string $source, float $started): void
    {
        $runs->markDone($this->runId, $result);
        $gate->releaseDispatchSlot($this->projectId, $this->runId, $slot);
        Log::info('insight run done', [
            'project_id' => $this->projectId,
            'run_id' => $this->runId,
            'source' => $source,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    private function endFailed(InsightRunStore $runs, InsightGate $gate, ?int $slot, ClickHouseErrorKind $kind): void
    {
        $runs->markFailed($this->runId, $kind);
        $gate->releaseDispatchSlot($this->projectId, $this->runId, $slot);
        Log::info('insight run failed', ['project_id' => $this->projectId, 'run_id' => $this->runId, 'kind' => $kind->value]);
    }

    /** Hands the run back to the queue, or ends it with kind `busy` once the waiting budget is spent. */
    private function releaseOrGiveUp(InsightRunStore $runs, InsightGate $gate, ?int $slot, int $delaySeconds): void
    {
        if ($this->attempts() >= self::MAX_ATTEMPTS) {
            $this->endFailed($runs, $gate, $slot, ClickHouseErrorKind::Busy);

            return;
        }

        $this->release($delaySeconds);
    }
}
