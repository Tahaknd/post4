<?php

namespace App\Domain\Capture;

use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Per-project ingest cap: events per fixed one-minute window (see config/capture.php). A request first
 * reserve()s its event count, and release()s whatever it did not end up producing. Fails open if the
 * cache is down.
 */
final class ProjectRateLimiter
{
    /** Length of a counter window; the counter key lives twice as long so a late increment still lands. */
    private const WINDOW_SECONDS = 60;

    /** Upper bound (inclusive) of the random seconds added to Retry-After so limited clients do not return together. */
    private const RETRY_JITTER_MAX_SECONDS = 5;

    /**
     * INCRBY, set the ttl when the key has none, and refund the reservation when it went over the limit, in
     * one atomic step. Returns the counter value after the increment and before the refund, so the caller
     * tells rejected (> limit) from allowed. KEYS[1] counter; ARGV[1] count, ARGV[2] ttl, ARGV[3] limit.
     */
    private const REDIS_RESERVE_SCRIPT = <<<'LUA'
local t = redis.call('INCRBY', KEYS[1], ARGV[1])
if redis.call('TTL', KEYS[1]) < 0 then redis.call('EXPIRE', KEYS[1], ARGV[2]) end
if t > tonumber(ARGV[3]) then redis.call('DECRBY', KEYS[1], ARGV[1]) end
return t
LUA;

    /**
     * DECRBY that never leaves the counter below zero, keeping the key's ttl. A key that is gone (or was
     * over-released) is deleted rather than recreated at 0 without an expiry. KEYS[1] counter; ARGV[1] count.
     */
    private const REDIS_RELEASE_SCRIPT = <<<'LUA'
local v = redis.call('DECRBY', KEYS[1], ARGV[1])
if v < 0 then redis.call('DEL', KEYS[1]); v = 0 end
return v
LUA;

    /** @var Closure(): int */
    private readonly Closure $jitter;

    /** @param  (Closure(): int)|null  $jitter  Seam for the Retry-After jitter in seconds; default random_int(0, 5). */
    public function __construct(?Closure $jitter = null)
    {
        $this->jitter = $jitter ?? static fn (): int => random_int(0, self::RETRY_JITTER_MAX_SECONDS);
    }

    /**
     * Reserves $count events in the project's window. Returns null when they fit; otherwise the
     * reservation is taken back (so rejected retries do not eat the budget) and the Retry-After seconds
     * (time to the window end plus jitter) are returned. Pair it with release() for the part of the
     * reservation that was not produced.
     *
     * Atomic on every store: one EVAL on Redis, otherwise add + increment (never read-modify-write).
     */
    public function reserve(int $projectId, int $count, int $nowTs): ?int
    {
        $limit = $this->effectiveLimit();
        if ($limit === null || $count <= 0) {
            return null;
        }

        $window = intdiv($nowTs, self::WINDOW_SECONDS);
        $key = "capture-rate:{$projectId}:{$window}";

        $rejected = LimiterOutage::guard(function () use ($projectId, $count, $limit, $window, $key): bool {
            $store = Cache::getStore();
            $total = $store instanceof RedisStore
                ? $this->reserveRedis($store, $key, $count, $limit)
                : $this->reserveGeneric($key, $count, $limit);

            if ($total <= $limit) {
                return false;
            }

            $this->warnOnce($projectId, $window, $limit, $count);

            return true;
        }, false);

        return $rejected ? max(1, ($window + 1) * self::WINDOW_SECONDS - $nowTs) + max(0, ($this->jitter)()) : null;
    }

    /**
     * Gives back $count events that were reserved but not produced (dropped, or never sent). $nowTs must be
     * the timestamp of the reservation, so the same window is addressed. The counter never goes below
     * zero. Best effort: a cache failure only means the window keeps the over-count until it ends.
     */
    public function release(int $projectId, int $count, int $nowTs): void
    {
        if ($count <= 0 || $this->effectiveLimit() === null) {
            return;
        }

        $key = "capture-rate:{$projectId}:".intdiv($nowTs, self::WINDOW_SECONDS);

        LimiterOutage::guard(function () use ($key, $count): void {
            $store = Cache::getStore();
            if ($store instanceof RedisStore) {
                $store->connection()->eval(self::REDIS_RELEASE_SCRIPT, 1, $store->getPrefix().$key, $count);

                return;
            }

            $left = Cache::decrement($key, $count);
            if (is_int($left) && $left < 0) {
                Cache::increment($key, -$left);
            }
        });
    }

    /** One EVAL; returns the counter after the increment (before a refund). */
    private function reserveRedis(RedisStore $store, string $key, int $count, int $limit): int
    {
        $total = $store->connection()->eval(
            self::REDIS_RESERVE_SCRIPT, 1, $store->getPrefix().$key, $count, $this->counterTtl(), $limit,
        );
        if (! is_int($total)) {
            throw new \UnexpectedValueException('project rate counter script did not return an integer');
        }

        return $total;
    }

    /** Portable path for the other stores (array, database, ...); returns the counter after the increment. */
    private function reserveGeneric(string $key, int $count, int $limit): int
    {
        Cache::add($key, 0, $this->counterTtl());
        $total = Cache::increment($key, $count);
        if (! is_int($total)) {
            throw new \UnexpectedValueException('project rate counter did not increment');
        }
        if ($total > $limit) {
            Cache::decrement($key, $count);
        }

        return $total;
    }

    private function warnOnce(int $projectId, int $window, int $limit, int $requested): void
    {
        // Once per window and project, whatever the number of rejected requests (counts only, no input).
        if (Cache::add("capture-rate-warned:{$projectId}:{$window}", 1, $this->counterTtl())) {
            Log::warning('capture project event rate limit exceeded', [
                'project_id' => $projectId,
                'limit' => $limit,
                'requested' => $requested,
            ]);
        }
    }

    /** Null when the limiter is disabled. */
    private function effectiveLimit(): ?int
    {
        $limit = (int) config('capture.project_events_per_minute');
        if ($limit <= 0) {
            return null;
        }

        // An enabled limit below the batch cap would reject a full batch forever (SDKs retry it forever).
        return max($limit, (int) config('capture.max_batch_size'));
    }

    private function counterTtl(): int
    {
        return 2 * self::WINDOW_SECONDS;
    }
}
