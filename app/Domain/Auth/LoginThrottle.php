<?php

namespace App\Domain\Auth;

use App\Domain\Capture\ClientIp;
use App\Domain\Capture\LimiterOutage;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Throttles FAILED sign-ins on two budgets: per (email, ip) and per ip alone (so one address cannot
 * walk through many accounts). Both are fixed windows (config auth.login_throttle) kept in the cache
 * store (Redis, with bounded timeouts). It is a safety net like the capture limiters: when the
 * store is unreachable the attempt is let through (fail open, one throttled warning).
 *
 * The email is hashed in the key (no addresses in Redis), and counting failures for unknown emails
 * the same way as for real ones keeps the throttle from revealing which accounts exist.
 */
final class LoginThrottle
{
    /** Seconds until the caller may try again, or null when it is not blocked. */
    public function blockedFor(string $email, string $ip): ?int
    {
        return LimiterOutage::guard(function () use ($email, $ip): ?int {
            $wait = null;

            foreach ([[$this->pairKey($email, $ip), $this->pairLimit()], [$this->ipKey($ip), $this->ipLimit()]] as [$key, $limit]) {
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    $wait = max($wait ?? 1, RateLimiter::availableIn($key));
                }
            }

            return $wait;
        }, limiter: 'login');
    }

    /** Counts a failed sign-in against both budgets. */
    public function recordFailure(string $email, string $ip): void
    {
        LimiterOutage::guard(function () use ($email, $ip): void {
            RateLimiter::hit($this->pairKey($email, $ip), $this->window());
            RateLimiter::hit($this->ipKey($ip), $this->window());
        }, limiter: 'login');
    }

    /** A successful sign-in forgives the (email, ip) failures; the per-ip budget keeps its count. */
    public function clear(string $email, string $ip): void
    {
        LimiterOutage::guard(fn () => RateLimiter::clear($this->pairKey($email, $ip)), limiter: 'login');
    }

    public function pairKey(string $email, string $ip): string
    {
        return 'login-fail:pair:'.hash('sha256', User::normaliseEmail($email).'|'.ClientIp::normalise($ip));
    }

    public function ipKey(string $ip): string
    {
        return 'login-fail:ip:'.ClientIp::normalise($ip);
    }

    private function pairLimit(): int
    {
        return (int) config('auth.login_throttle.email_ip_limit');
    }

    private function ipLimit(): int
    {
        return (int) config('auth.login_throttle.ip_limit');
    }

    private function window(): int
    {
        return (int) config('auth.login_throttle.window_seconds');
    }
}
