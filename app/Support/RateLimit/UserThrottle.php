<?php

namespace App\Support\RateLimit;

use Illuminate\Support\Facades\RateLimiter;

/**
 * A fixed-window budget per signed-in user and "family" (a named kind of action, e.g. projects.create), counted
 * BEFORE the action. Each family has its own bucket, so using up one never starves another. Like the other limiters
 * it FAILS OPEN: with the cache unreachable the action is allowed and a throttled warning is logged
 * (LimiterOutage). Limits live in config/ratelimit.php (`user_throttles`).
 */
final class UserThrottle
{
    /**
     * Counts one use. Returns the seconds to wait when the user is over budget, null when it may proceed.
     */
    public function hit(string $family, int|string $userId, int $limit, int $windowSeconds): ?int
    {
        return LimiterOutage::guard(function () use ($family, $userId, $limit, $windowSeconds): ?int {
            $key = "user-throttle:{$family}:{$userId}";

            return RateLimiter::hit($key, $windowSeconds) > $limit ? max(1, RateLimiter::availableIn($key)) : null;
        }, limiter: 'user-throttle');
    }
}
