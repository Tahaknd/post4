<?php

namespace App\Http\Middleware;

use App\Http\ApiErrors;
use App\Http\NextUrl;
use App\Http\ReservedPaths;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Keeps anonymous visitors from getting a session (a Redis key and two Set-Cookie headers) just for
 * being bounced to /login or for hitting a path that does not exist. First in the web group, before
 * StartSession.
 *
 * A request without the session cookie cannot be signed in, so:
 *  - when its route requires `auth` the answer is already known: redirect to /login (401 JSON for API-style
 *    callers) with the requested page in `?next=` (GET and HEAD only, see NextUrl), no session, no cookie;
 *  - when it only matched the web fallback route (an unknown path) it is answered with a 404 right away.
 *    The same goes for capture and SDK paths even WITH a cookie: they never belong to a session.
 * A request WITH a session cookie takes the normal path (StartSession, then Authenticate / the fallback).
 *
 * This runs ahead of HandleInertiaRequests, so the redirect it builds has to do two things that middleware
 * would have done: use 303 for a method that is not GET or HEAD (a 302 after PUT/PATCH/DELETE may be repeated
 * with that method by some clients) and send `Vary: X-Inertia`.
 *
 * A user already resolved on the guard (actingAs() in tests, a future token guard) is left alone.
 */
class RejectSessionlessGuests
{
    public function __construct(private readonly Router $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route?->isFallback && ($this->hasNoSession($request) || ReservedPaths::collides($request->path()))) {
            throw new NotFoundHttpException;
        }

        if ($this->hasNoSession($request) && $this->requiresAuthentication($request) && ! auth()->hasUser()) {
            // `api/*` is JSON by definition: a client that forgot the Accept header still gets a 401, not a redirect.
            return $request->expectsJson() || ApiErrors::isApiRequest($request)
                ? response()->json(ApiErrors::body('unauthenticated', 'Unauthenticated.'), 401)
                : self::loginRedirect($request);
        }

        return $next($request);
    }

    /** The /login URL for a guest, with `?next=` for a page they can come back to. */
    public static function loginUrl(Request $request): string
    {
        $next = $request->isMethod('GET') || $request->isMethod('HEAD') ? NextUrl::sanitise($request->getRequestUri()) : null;

        return route('login', $next === null ? [] : ['next' => $next]);
    }

    /** A redirect with the status HandleInertiaRequests would have chosen, for places that run before it. */
    public static function redirectFor(Request $request, string $url): Response
    {
        $safe = $request->isMethod('GET') || $request->isMethod('HEAD');

        return redirect($url, $safe ? 302 : 303)->header('Vary', 'X-Inertia');
    }

    private static function loginRedirect(Request $request): Response
    {
        return self::redirectFor($request, self::loginUrl($request));
    }

    private function hasNoSession(Request $request): bool
    {
        // The raw cookie: this runs before EncryptCookies. A forged or stale value is not our business, the
        // session layer discards it (and then does start a session, which is the safe direction).
        return ! $request->cookies->has((string) config('session.cookie'));
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
}
