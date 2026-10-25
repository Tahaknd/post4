<?php

namespace App\Domain\Insights\Runs;

use App\Support\RateLimit\LimiterOutage;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Cache as CacheFacade;

/**
 * The three small gates around asynchronous insight runs. All of them are cache locks with an owner and a TTL
 * (atomic `SET NX PX` on Redis, no key without an expiry), on `config('insights.cache_store')`.
 *
 *  1. Dispatch slots (`insights:dispatch:{project}:{n}`): at most `max_runs_per_project` runs of one project exist
 *     (queued or running) at any time. A request takes a free slot BEFORE it queues anything and answers 429 when
 *     none is free; the job gives the slot back when the run reaches a terminal state. A job that dies keeps the
 *     slot until the run TTL (`run_ttl_seconds`) lapses. Fail-CLOSED: when the store is down nothing could be
 *     delivered anyway, so the caller answers 503.
 *  2. Running slots (`insights:running:{project}:{n}`): at most `max_running_per_project` queries of one project
 *     run on ClickHouse at once, taken by the job right before it queries and given back right after. Fail-OPEN
 *     (LimiterOutage): a limiter is a safety net, and a dead store would fail the job's own record writes anyway.
 *  3. Single flight (`insights:flight:{project}:{hash}`): of several runs of the SAME definition only the holder
 *     queries ClickHouse, the others copy the holder's cached result. Fail-open like the running slots.
 *
 * The TTL of a running slot and a flight lock is `flight_lock_seconds` (60), above the job timeout (45), so a
 * killed job frees them by itself.
 */
final class InsightGate
{
    private const string LIMITER = 'insights';

    public function __construct(
        /** Tests inject a store; null resolves config('insights.cache_store') on every use. */
        private readonly ?Cache $cache = null,
    ) {}

    /** The slot index taken, or null when all of the project's slots are in use. @throws \Throwable when the store is down */
    public function acquireDispatchSlot(int $projectId, string $runId): ?int
    {
        $slots = max(1, (int) config('insights.max_runs_per_project'));
        $ttl = (int) config('insights.run_ttl_seconds');

        for ($slot = 0; $slot < $slots; $slot++) {
            if ($this->store()->lock(self::dispatchKey($projectId, $slot), $ttl, $runId)->get()) {
                return $slot;
            }
        }

        return null;
    }

    /** Gives a dispatch slot back; only the run that holds it can (the owner is the run id). Never throws. */
    public function releaseDispatchSlot(int $projectId, string $runId, ?int $slot): void
    {
        if ($slot === null) {
            return;
        }

        LimiterOutage::guard(
            fn () => $this->store()->restoreLock(self::dispatchKey($projectId, $slot), $runId)->release(),
            limiter: self::LIMITER,
        );
    }

    /** A permit for one ClickHouse query of the project, or null when its running slots are full. */
    public function acquireRunning(int $projectId, string $runId): ?Permit
    {
        return LimiterOutage::guard(function () use ($projectId, $runId): ?Permit {
            $slots = max(1, (int) config('insights.max_running_per_project'));

            for ($slot = 0; $slot < $slots; $slot++) {
                $lock = $this->store()->lock("insights:running:{$projectId}:{$slot}", $this->lockSeconds(), $runId);
                if ($lock->get()) {
                    return new Permit($lock);
                }
            }

            return null;
        }, fallback: Permit::open(), limiter: self::LIMITER);
    }

    /** The single-flight lock of one definition, or null when another run of it holds it. */
    public function acquireFlight(int $projectId, string $definitionHash, string $runId): ?Permit
    {
        return LimiterOutage::guard(function () use ($projectId, $definitionHash, $runId): ?Permit {
            $lock = $this->store()->lock("insights:flight:{$projectId}:{$definitionHash}", $this->lockSeconds(), $runId);

            return $lock->get() ? new Permit($lock) : null;
        }, fallback: Permit::open(), limiter: self::LIMITER);
    }

    public static function dispatchKey(int $projectId, int $slot): string
    {
        return "insights:dispatch:{$projectId}:{$slot}";
    }

    private function lockSeconds(): int
    {
        return max(1, (int) config('insights.flight_lock_seconds'));
    }

    private function store(): Cache
    {
        return $this->cache ?? CacheFacade::store((string) config('insights.cache_store'));
    }
}
