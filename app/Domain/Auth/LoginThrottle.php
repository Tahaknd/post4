<?php

namespace App\Domain\Auth;

use App\Models\User;
use App\Support\RateLimit\ClientIp;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate-limits sign-in attempts on three fixed-window budgets (config auth.login_throttle): per (email, ip),
 * per ip alone (one address walking through many accounts) and per email alone (a botnet that gives every
 * address its own few tries). Over ANY budget the answer is 429 + Retry-After for the window: it limits
 * the rate, it never locks an account (the owner can sign in again as soon as the window passes, and a
 * sign-in that is already open is not affected).
 *
 * The attempt is counted BEFORE the password is checked: hit() increments atomically (INCR in Redis) and
 * only the callers that land at or under the limit go on to verify, so N parallel requests cannot all slip
 * through on a stale read; the overshoot is zero. A successful sign-in then gives its attempt back (the
 * (email, ip) counter is cleared, the other two lose one), so a user who types the right password is not
 * what exhausts a budget. The per-email budget is the one an attacker can use against the victim (spend it
 * and the victim waits for the window): it is therefore well above the (email, ip) one.
 *
 * Like the capture limiters this is a safety net that FAILS OPEN: if the store is unreachable the attempt is
 * let through and a throttled warning is logged. Emails are hashed in the keys (no addresses in Redis).
 * Behind a reverse proxy the ip is the proxy's unless TRUSTED_PROXIES is set (config/proxies.php).
 */
final class LoginThrottle
{
    /**
     * Counts one attempt. Returns the seconds to wait when it is over a budget, null when it may proceed.
     */
    public function hit(string $email, string $ip): ?int
    {
        return LimiterOutage::guard(function () use ($email, $ip): ?int {
            $wait = null;

            foreach ($this->budgets($email, $ip) as [$key, $limit]) {
                if (RateLimiter::hit($key, $this->window()) > $limit) {
                    $wait = max($wait ?? 1, RateLimiter::availableIn($key));
                }
            }

            return $wait;
        }, limiter: 'login');
    }

    /** Gives a successful sign-in's attempt back (see the class comment). */
    public function succeeded(string $email, string $ip): void
    {
        LimiterOutage::guard(function () use ($email, $ip): void {
            RateLimiter::clear($this->pairKey($email, $ip));
            RateLimiter::decrement($this->ipKey($ip), $this->window());
            RateLimiter::decrement($this->emailKey($email), $this->window());
        }, limiter: 'login');
    }

    public function pairKey(string $email, string $ip): string
    {
        return 'login-attempts:pair:'.hash('sha256', User::normaliseEmail($email).'|'.ClientIp::normalise($ip));
    }

    public function ipKey(string $ip): string
    {
        return 'login-attempts:ip:'.ClientIp::normalise($ip);
    }

    public function emailKey(string $email): string
    {
        return 'login-attempts:email:'.hash('sha256', User::normaliseEmail($email));
    }

    /** @return list<array{string, int}> */
    private function budgets(string $email, string $ip): array
    {
        return [
            [$this->pairKey($email, $ip), (int) config('auth.login_throttle.email_ip_limit')],
            [$this->ipKey($ip), (int) config('auth.login_throttle.ip_limit')],
            [$this->emailKey($email), (int) config('auth.login_throttle.email_limit')],
        ];
    }

    private function window(): int
    {
        return (int) config('auth.login_throttle.window_seconds');
    }
}
