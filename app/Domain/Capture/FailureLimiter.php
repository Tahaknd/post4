<?php

namespace App\Domain\Capture;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per client ip limiter for UNAUTHENTICATED capture outcomes only (see config/capture.php). It bounds
 * Postgres and negative-cache load; it does not protect the key. Fails open if the cache is down.
 */
final class FailureLimiter
{
    /** Seconds until the ip may retry, or null when it is not blocked. */
    public function blockedFor(string $ip): ?int
    {
        $key = $this->key($ip);

        return LimiterOutage::guard(function () use ($key): ?int {
            if (! RateLimiter::tooManyAttempts($key, $this->limit())) {
                return null;
            }

            return max(1, RateLimiter::availableIn($key));
        });
    }

    /** Counts a failed (unauthenticated or undecodable) request against the ip; warns once when the limit is reached. */
    public function record(string $ip): void
    {
        $key = $this->key($ip);
        $limit = $this->limit();

        $attempts = LimiterOutage::guard(
            fn () => RateLimiter::hit($key, (int) config('capture.auth_fail_window_seconds')),
        );

        if ($attempts === $limit) {
            // Best effort: a failing logger must not fail the request that was already answered.
            LimiterOutage::guard(
                fn () => Log::warning('capture failure limit reached, blocking caller', ['limiter_key' => $key, 'limit' => $limit]),
            );
        }
    }

    /** Cache key for an ip (see ClientIp::normalise for how addresses are grouped). */
    public function key(string $ip): string
    {
        return 'capture-auth-fail:'.ClientIp::normalise($ip);
    }

    private function limit(): int
    {
        return (int) config('capture.auth_fail_limit');
    }
}
