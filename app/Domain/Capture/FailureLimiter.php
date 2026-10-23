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

    /**
     * Cache key for an ip. IPv4 is used as is and an IPv4-mapped IPv6 address (::ffff:a.b.c.d) counts as
     * that IPv4. Any other IPv6 address is grouped by its /64 prefix, because one subscriber commonly
     * owns a whole /64 and per-address budgets would be unlimited. Empty or unparseable input shares
     * the single key "unknown".
     */
    public function key(string $ip): string
    {
        return 'capture-auth-fail:'.$this->normalise($ip);
    }

    private function normalise(string $ip): string
    {
        // A NUL byte makes inet_pton throw a ValueError (unreachable through Request::ip(), cheap to rule out).
        $packed = str_contains($ip, "\0") ? false : @inet_pton($ip);
        if ($packed === false) {
            return 'unknown';
        }

        if (strlen($packed) === 4) {
            return inet_ntop($packed);
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return inet_ntop(substr($packed, 12));
        }

        return 'v6-'.bin2hex(substr($packed, 0, 8));
    }

    private function limit(): int
    {
        return (int) config('capture.auth_fail_limit');
    }
}
