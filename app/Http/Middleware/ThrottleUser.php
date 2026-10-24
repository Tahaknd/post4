<?php

namespace App\Http\Middleware;

use App\Support\RateLimit\UserThrottle;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `throttle.user:{family}`: a per-user budget for one family of actions (limit and window in
 * config('ratelimit.user_throttles')[family]). Over budget it throws a 429 with Retry-After, which the
 * exception handler turns into JSON for JSON callers and the Error page for HTML ones. It sits after `auth`; a
 * request without a user is passed on (the auth middleware decides about it).
 */
class ThrottleUser
{
    public function __construct(private readonly UserThrottle $throttle) {}

    public function handle(Request $request, Closure $next, string $family): Response
    {
        $user = $request->user();
        // Indexed, not a dotted config path: the family names themselves contain a dot.
        $budget = config('ratelimit.user_throttles')[$family] ?? null;

        if ($user === null || ! is_array($budget)) {
            return $next($request);
        }

        $wait = $this->throttle->hit($family, $user->getAuthIdentifier(), (int) $budget['limit'], (int) $budget['window_seconds']);

        if ($wait !== null) {
            throw new ThrottleRequestsException("Too many attempts. Try again in {$wait} seconds.", headers: ['Retry-After' => $wait]);
        }

        return $next($request);
    }
}
