<?php

namespace App\Domain\Capture;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The fail-open pattern shared by the capture limiters: a limiter is a safety net, so when the cache is
 * unreachable the request is let through and only a (throttled) warning is written.
 */
final class LimiterOutage
{
    /** @var array<string, int> unix time of the last "limiter unavailable" warning per limiter family, so a Redis outage cannot flood the log */
    private static array $lastWarningAt = [];

    /**
     * Runs $callback and returns its result; on any \Throwable logs at most one warning per
     * capture.limiter_warning_interval_seconds (per process and $limiter family) and returns $fallback.
     * $limiter only names the family in the log line ('capture' for the ingest limiters, 'login' for the
     * sign-in throttle) and keeps one family's warning from muting another's.
     *
     * @template T
     * @template F
     *
     * @param  Closure(): T  $callback
     * @param  F  $fallback
     * @return T|F
     */
    public static function guard(Closure $callback, mixed $fallback = null, string $limiter = 'capture'): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            self::warn($e, $limiter);

            return $fallback;
        }
    }

    /** @internal Test seam: forgets the last warning so the next failure logs again. */
    public static function reset(): void
    {
        self::$lastWarningAt = [];
    }

    private static function warn(\Throwable $e, string $limiter): void
    {
        $now = time();
        if ($now - (self::$lastWarningAt[$limiter] ?? 0) < (int) config('capture.limiter_warning_interval_seconds')) {
            return;
        }
        self::$lastWarningAt[$limiter] = $now;

        Log::warning($limiter.' rate limiter unavailable, failing open', [
            'exception' => $e::class,
            // The class alone cannot tell a timeout from READONLY, OOM or WRONGTYPE (or a programming error).
            'message' => Str::limit($e->getMessage(), 200),
        ]);
    }
}
