<?php

namespace App\Domain\Capture;

use App\Support\RateLimit\ClientIp;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per client ip cap on SUCCESSFUL SDK stub calls (config, flags and decide together, `capture.stub_calls_per_minute`,
 * a fixed one minute window; 0 disables). The failure limiter only sees bad tokens, so without this an ip with a valid
 * token could make the server decode and answer an unbounded number of flag requests. Fails open when the cache is down.
 */
final class StubCallLimiter
{
    private const WINDOW_SECONDS = 60;

    /** Seconds until the ip may call again, or null while it is under the cap. */
    public function exceededFor(string $ip): ?int
    {
        $limit = $this->limit();
        if ($limit <= 0) {
            return null;
        }

        $key = $this->key($ip);

        return LimiterOutage::guard(function () use ($key, $limit): ?int {
            return RateLimiter::tooManyAttempts($key, $limit) ? max(1, RateLimiter::availableIn($key)) : null;
        });
    }

    /** Counts one answered call. */
    public function hit(string $ip): void
    {
        if ($this->limit() > 0) {
            LimiterOutage::guard(fn () => RateLimiter::hit($this->key($ip), self::WINDOW_SECONDS));
        }
    }

    public function key(string $ip): string
    {
        return 'sdk-stub-calls:'.ClientIp::normalise($ip);
    }

    private function limit(): int
    {
        return (int) config('capture.stub_calls_per_minute');
    }
}
