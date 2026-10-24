<?php

namespace App\Domain\Projects;

use App\Models\User;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A modest per-user budget for the web's project writes (create, rotate key, delete), so a script or a stuck
 * button cannot churn through keys or projects. Fixed window, counted before the write. Like the other
 * limiters it FAILS OPEN: with the cache unreachable the write goes ahead and a throttled warning is logged.
 */
final class ProjectWriteThrottle
{
    /** Seconds to wait when the user is over budget, null when the write may proceed. */
    public function hit(User $user): ?int
    {
        return LimiterOutage::guard(function () use ($user): ?int {
            $key = 'project-writes:'.$user->getKey();

            return RateLimiter::hit($key, (int) config('ratelimit.project_writes.window_seconds')) > (int) config('ratelimit.project_writes.limit')
                ? max(1, RateLimiter::availableIn($key))
                : null;
        }, limiter: 'project-writes');
    }
}
