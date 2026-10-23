<?php

namespace App\Domain\Capture;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Per-project ingest cap: producible events per fixed one-minute window (see config/capture.php).
 * Fails open if the cache is down.
 */
final class ProjectRateLimiter
{
    /**
     * Charges $count events to the project's window. Returns null when they fit; otherwise the charge
     * is taken back (so rejected retries do not eat the budget) and the seconds until the window ends
     * are returned.
     *
     * Atomic through the cache store (add + increment, never read-modify-write). The key carries a
     * capture.project_counter_ttl_seconds ttl so a window outlives its minute and then disappears.
     */
    public function charge(int $projectId, int $count, int $nowTs): ?int
    {
        $limit = $this->effectiveLimit();
        if ($limit === null || $count === 0) {
            return null;
        }

        $window = intdiv($nowTs, 60);
        $key = $this->key($projectId, $window);
        $ttl = $this->counterTtl();

        $rejected = LimiterOutage::guard(function () use ($projectId, $count, $limit, $window, $key, $ttl): bool {
            Cache::add($key, 0, $ttl);
            $total = Cache::increment($key, $count);
            if (! is_int($total)) {
                throw new \UnexpectedValueException('project rate counter did not increment');
            }
            if ($total <= $limit) {
                return false;
            }

            Cache::decrement($key, $count);
            $this->warnOnce($projectId, $window, $limit, $count);

            return true;
        }, false);

        return $rejected ? $this->retryAfter($window, $nowTs) : null;
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
        return max(1, ($window + 1) * 60 - $nowTs);
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
