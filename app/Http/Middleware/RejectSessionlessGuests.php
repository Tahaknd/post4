<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps anonymous visitors from getting a session (a Redis key and two Set-Cookie headers) just for
 * being bounced to /login. First in the web group, before StartSession.
 *
 * A request without the session cookie cannot be signed in, so when its route requires `auth` the
 * answer is already known: redirect to /login (401 JSON for API-style callers) without starting a
 * session, adding a cookie or remembering an intended URL. A request WITH a session cookie takes the
 * normal path (StartSession, then Authenticate). Sessions therefore start only for the guest pages
 * (/login, /register), for POSTs and for requests that present a cookie.
 *
 * A user already resolved on the guard (actingAs() in tests, a future token guard) is left alone.
 */
class RejectSessionlessGuests
{
    public function __construct(private readonly Router $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->hasSessionCookie($request) && $this->requiresAuthentication($request) && ! $this->alreadyAuthenticated()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect()->route('login');
        }

        return $next($request);
    }

    private function hasSessionCookie(Request $request): bool
    {
        // The raw cookie: this runs before EncryptCookies. A forged or stale value is not our business, the
        // session layer discards it (and then does start a session, which is the safe direction).
        return $request->cookies->has((string) config('session.cookie'));
    }

    private function requiresAuthentication(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, Authenticate::class)) {
                return true;
            }
        }

        return false;
    }

    private function alreadyAuthenticated(): bool
    {
        return auth()->hasUser();
    }
}
