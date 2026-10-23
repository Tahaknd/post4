<?php

namespace App\Domain\Capture;

use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Per-project ingest cap: producible events per fixed one-minute window (see config/capture.php).
 * Fails open if the cache is down.
 */
final class ProjectRateLimiter
{
    /** Upper bound (inclusive) of the random seconds added to Retry-After so limited clients do not return together. */
    private const RETRY_JITTER_MAX_SECONDS = 5;

    /**
     * INCRBY, set the ttl when the key has none, and refund the charge when it went over the limit, in one
     * atomic step. Returns the counter value after the increment and before the refund, so the caller
     * tells rejected (> limit) from allowed. KEYS[1] counter; ARGV[1] count, ARGV[2] ttl, ARGV[3] limit.
     */
    private const REDIS_CHARGE_SCRIPT = <<<'LUA'
local t = redis.call('INCRBY', KEYS[1], ARGV[1])
if redis.call('TTL', KEYS[1]) < 0 then redis.call('EXPIRE', KEYS[1], ARGV[2]) end
if t > tonumber(ARGV[3]) then redis.call('DECRBY', KEYS[1], ARGV[1]) end
return t
LUA;

    /** @var Closure(): int */
    private readonly Closure $jitter;

    /** @param  (Closure(): int)|null  $jitter  Seam for the Retry-After jitter in seconds; default random_int(0, 5). */
    public function __construct(?Closure $jitter = null)
    {
        $this->jitter = $jitter ?? static fn (): int => random_int(0, self::RETRY_JITTER_MAX_SECONDS);
    }

    /**
     * Charges $count events to the project's window. Returns null when they fit; otherwise the charge
     * is taken back (so rejected retries do not eat the budget) and the Retry-After seconds (time to the
     * window end plus jitter) are returned.
     *
     * A request without producible events (count 0) is never charged or rejected here. Note that
     * isExhausted() runs before normalisation and cannot know the count, so such a request is still
     * refused while the budget is spent; CPU protection for flooding with undecodable or all-dropped
     * bodies belongs at the reverse proxy.
     *
     * Atomic on every store: one EVAL on Redis, otherwise add + increment (never read-modify-write).
     * The key carries a capture.project_counter_ttl_seconds ttl so a window outlives its minute and
     * then disappears.
     */
    public function charge(int $projectId, int $count, int $nowTs): ?int
    {
        $limit = $this->effectiveLimit();
        if ($limit === null || $count === 0) {
            return null;
        }

        $window = intdiv($nowTs, 60);
        $key = $this->key($projectId, $window);

        $rejected = LimiterOutage::guard(function () use ($projectId, $count, $limit, $window, $key): bool {
            $store = Cache::getStore();
            $total = $store instanceof RedisStore
                ? $this->chargeRedis($store, $key, $count, $limit)
                : $this->chargeGeneric($key, $count, $limit);

            if ($total <= $limit) {
                return false;
            }

            $this->warnOnce($projectId, $window, $limit, $count);

            return true;
        }, false);

        return $rejected ? $this->retryAfter($window, $nowTs) : null;
    }

    /**
     * Cheap pre-check, meant to run before the request is normalised: Retry-After seconds when the
     * project's counter for this window has already reached the limit, otherwise null. It reads only
     * (one cache get) and never charges. $requested is the number of events in the request and only
     * feeds the once-per-window warning. Fails open.
     */
    public function isExhausted(int $projectId, int $nowTs, int $requested): ?int
    {
        $limit = $this->effectiveLimit();
        if ($limit === null) {
            return null;
        }

        $window = intdiv($nowTs, 60);

        $exhausted = LimiterOutage::guard(function () use ($projectId, $requested, $limit, $window): bool {
            $current = Cache::get($this->key($projectId, $window));
            if (! is_numeric($current) || (int) $current < $limit) {
                return false;
            }

            $this->warnOnce($projectId, $window, $limit, $requested);

            return true;
        }, false);

        return $exhausted ? $this->retryAfter($window, $nowTs) : null;
    }

    /** One EVAL; returns the counter after the increment (before a refund). */
    private function chargeRedis(RedisStore $store, string $key, int $count, int $limit): int
    {
        $total = $store->connection()->eval(
            self::REDIS_CHARGE_SCRIPT, 1, $store->getPrefix().$key, $count, max(1, $this->counterTtl()), $limit,
        );
        if (! is_int($total)) {
            throw new \UnexpectedValueException('project rate counter script did not return an integer');
        }

        return $total;
    }

    /** Portable path for the other stores (array, database, ...); returns the counter after the increment. */
    private function chargeGeneric(string $key, int $count, int $limit): int
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

    private function retryAfter(int $window, int $nowTs): int
    {
        return max(1, ($window + 1) * 60 - $nowTs) + max(0, ($this->jitter)());
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
        return (int) config('capture.project_counter_ttl_seconds');
    }

    private function key(int $projectId, int $window): string
    {
        return "capture-rate:{$projectId}:{$window}";
    }
}
