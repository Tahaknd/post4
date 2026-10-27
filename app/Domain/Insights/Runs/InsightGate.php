<?php

namespace App\Domain\Insights\Runs;

use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The small gates around asynchronous insight runs. All of them are cache locks with an owner and a TTL (atomic
 * `SET NX PX` on Redis, no key without an expiry), on the insight store (`config('insights.cache_store')`, given to
 * this class by the container).
 *
 *  1. Dispatch slots, two kinds: per PROJECT (`insights:dispatch:{project}:{n}`, `max_active_runs_per_project`) and per
 *     USER (`insights:user:{user}:{n}`, `max_active_runs_per_user`). A request takes a slot of each kind BEFORE it
 *     queues anything and answers 429 when none is free; the job gives them back on EVERY exit (done, failed,
 *     cancelled, skipped, killed). Owner-checked (the owner is the run id): only the run that holds a slot can free
 *     it, from any process. A job that dies without freeing keeps a slot until the run TTL lapses. Fail-CLOSED: when
 *     the store is down nothing could be delivered anyway, so the caller answers 503.
 *  2. Running slots (`insights:running:{project}:{n}`, `max_concurrent_queries_per_project`): at most that many
 *     queries of one project run on ClickHouse at once, taken by the job right before it queries and given back
 *     right after. Fail-OPEN (LimiterOutage): a limiter is a safety net, and a dead store would fail the job's own
 *     record writes anyway.
 *  3. Single flight (`insights:flight:{type}:{project}:{hash}`): of several runs of the SAME definition only the
 *     holder queries ClickHouse, the others copy its cached result, or its failure through a short marker
 *     (`insights:flightfail:{type}:{project}:{hash}`, `flightfail_ttl_seconds`), so waiters do not repeat a query that
 *     just timed out. Fail-open like the running slots.
 *  4. Inflight (`insights:inflight:{project}:{user}:{hash}`, `inflight_ttl_seconds`): while a run of a user is
 *     queued or running, an identical POST of the same user is answered with that run (the value is the run id;
 *     claimed with an atomic `add`).
 *
 * The TTL of a running slot and a flight lock is `lock_ttl_seconds` (50): above the job timeout (45), so a killed job
 * frees them by itself, and below the queue's retry_after (90), so a takeover finds them expired.
 */
final class InsightGate
{
    private const string LIMITER = 'insights';

    public function __construct(private readonly Cache $cache) {}

    /** The slot index taken, or null when all of the project's slots are in use. @throws \Throwable when the store is down */
    public function acquireProjectSlot(int $projectId, string $runId): ?int
    {
        return $this->acquireSlot(fn (int $n): string => self::projectSlotKey($projectId, $n), (int) config('insights.max_active_runs_per_project'), $runId);
    }

    /** The slot index taken, or null when the user already has the maximum of active runs. @throws \Throwable when the store is down */
    public function acquireUserSlot(int $userId, string $runId): ?int
    {
        return $this->acquireSlot(fn (int $n): string => self::userSlotKey($userId, $n), (int) config('insights.max_active_runs_per_user'), $runId);
    }

    /** Gives both dispatch slots of a run back; only the owner (the run id) can. Never throws, safe to repeat. */
    public function releaseSlots(int $projectId, int $userId, string $runId, ?int $projectSlot, ?int $userSlot): void
    {
        foreach ([[$projectSlot, self::projectSlotKey($projectId, (int) $projectSlot)], [$userSlot, self::userSlotKey($userId, (int) $userSlot)]] as [$slot, $key]) {
            if ($slot === null) {
                continue;
            }

            LimiterOutage::guard(fn () => $this->cache->restoreLock($key, $runId)->release(), limiter: self::LIMITER);
        }
    }

    /** A permit for one ClickHouse query of the project, or null when its running slots are full. */
    public function acquireRunning(int $projectId, string $runId): ?Permit
    {
        return LimiterOutage::guard(function () use ($projectId, $runId): ?Permit {
            $slots = max(1, (int) config('insights.max_concurrent_queries_per_project'));

            for ($slot = 0; $slot < $slots; $slot++) {
                $lock = $this->cache->lock("insights:running:{$projectId}:{$slot}", $this->lockSeconds(), $runId);
                if ($lock->get()) {
                    return new Permit($lock);
                }
            }

            return null;
        }, fallback: Permit::open(), limiter: self::LIMITER);
    }

    /** The single-flight lock of one definition, or null when another run of it holds it. */
    public function acquireFlight(string $type, int $projectId, string $definitionHash, string $runId): ?Permit
    {
        return LimiterOutage::guard(function () use ($type, $projectId, $definitionHash, $runId): ?Permit {
            $lock = $this->cache->lock("insights:flight:{$type}:{$projectId}:{$definitionHash}", $this->lockSeconds(), $runId);

            return $lock->get() ? new Permit($lock) : null;
        }, fallback: Permit::open(), limiter: self::LIMITER);
    }

    /** The holder failed deterministically: tell the waiters of the same definition (they copy it instead of querying). */
    public function rememberFlightFailure(string $type, int $projectId, string $definitionHash, ClickHouseErrorKind $kind): void
    {
        LimiterOutage::guard(fn () => $this->cache->put(self::flightFailureKey($type, $projectId, $definitionHash), $kind->value, max(1, (int) config('insights.flightfail_ttl_seconds'))), limiter: self::LIMITER);
    }

    public function flightFailure(string $type, int $projectId, string $definitionHash): ?ClickHouseErrorKind
    {
        $value = LimiterOutage::guard(fn () => $this->cache->get(self::flightFailureKey($type, $projectId, $definitionHash)), limiter: self::LIMITER);

        return is_string($value) ? ClickHouseErrorKind::tryFrom($value) : null;
    }

    /**
     * Records that `$runId` is the live run of this (project, user, definition): an atomic `add`, so of two identical
     * simultaneous POSTs exactly one wins. False when another live run already holds it.
     *
     * @throws \Throwable when the store is down
     */
    public function claimInflight(int $projectId, int $userId, string $definitionHash, string $runId): bool
    {
        return $this->cache->add(self::inflightKey($projectId, $userId, $definitionHash), $runId, max(1, (int) config('insights.inflight_ttl_seconds')));
    }

    /** The run id that holds the inflight key, or null. @throws \Throwable when the store is down */
    public function inflightRunId(int $projectId, int $userId, string $definitionHash): ?string
    {
        $holder = $this->cache->get(self::inflightKey($projectId, $userId, $definitionHash));

        return is_string($holder) && InsightRunStore::isValidId($holder) ? $holder : null;
    }

    /**
     * Frees the inflight key if `$runId` holds it (compare, then forget: the window between the two is a few
     * microseconds and the worst case is one duplicate run later). Never throws, safe to repeat.
     */
    public function releaseInflight(int $projectId, int $userId, string $definitionHash, string $runId): void
    {
        LimiterOutage::guard(function () use ($projectId, $userId, $definitionHash, $runId): void {
            $key = self::inflightKey($projectId, $userId, $definitionHash);
            if ($this->cache->get($key) === $runId) {
                $this->cache->forget($key);
            }
        }, limiter: self::LIMITER);
    }

    public static function projectSlotKey(int $projectId, int $slot): string
    {
        return "insights:dispatch:{$projectId}:{$slot}";
    }

    public static function userSlotKey(int $userId, int $slot): string
    {
        return "insights:user:{$userId}:{$slot}";
    }

    public static function inflightKey(int $projectId, int $userId, string $definitionHash): string
    {
        return "insights:inflight:{$projectId}:{$userId}:{$definitionHash}";
    }

    public static function flightFailureKey(string $type, int $projectId, string $definitionHash): string
    {
        return "insights:flightfail:{$type}:{$projectId}:{$definitionHash}";
    }

    /** @param  callable(int): string  $key */
    private function acquireSlot(callable $key, int $slots, string $runId): ?int
    {
        $ttl = max(1, (int) config('insights.run_ttl_seconds'));

        for ($slot = 0; $slot < max(1, $slots); $slot++) {
            if ($this->cache->lock($key($slot), $ttl, $runId)->get()) {
                return $slot;
            }
        }

        return null;
    }

    private function lockSeconds(): int
    {
        return max(1, (int) config('insights.lock_ttl_seconds'));
    }
}
