<?php

namespace App\Domain\Capture;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * The fail-open pattern shared by the capture limiters: a limiter is a safety net, so when the cache is
 * unreachable the request is let through and only a (throttled) warning is written.
 */
final class LimiterOutage
{
    /** Unix time of the last "limiter unavailable" warning, so a Redis outage cannot flood the log. */
    private static int $lastWarningAt = 0;

    /**
     * Runs $callback and returns its result; on any \Throwable logs at most one warning per
     * capture.limiter_warning_interval_seconds (per process) and returns $fallback.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|mixed
     */
    public static function guard(Closure $callback, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            self::warn($e);

            return $fallback;
        }
    }

    /** @internal Test seam: forgets the last warning so the next failure logs again. */
    public static function reset(): void
    {
        self::$lastWarningAt = 0;
    }

    private static function warn(\Throwable $e): void
    {
        $now = time();
        if ($now - self::$lastWarningAt < (int) config('capture.limiter_warning_interval_seconds')) {
            return;
        }
        self::$lastWarningAt = $now;

        Log::warning('capture rate limiter unavailable, failing open', ['exception' => $e::class]);
    }
}
