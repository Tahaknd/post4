<?php

namespace App\Http;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectSessionlessGuests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Turns failed HTML requests on UI (web) routes into the Inertia `Error` page, and an expired page (419)
 * into a redirect back with a message. Registered through `$exceptions->respond()` in bootstrap/app.php.
 *
 * It leaves everything else alone:
 *  - JSON callers (Accept: application/json, /api/*): the JSON the framework already built;
 *  - capture and SDK paths (ReservedPaths::SEGMENTS: /e, /batch, /capture, /i/..., ...): they must answer
 *    SDKs with JSON errors and never render a page;
 *  - /up, /build, /storage, /_inertia: health checks and assets, plain responses;
 *  - 500 and 503 while APP_DEBUG is on: the developer's debug page.
 * Horizon's HTML pages are not excluded: a non-administrator gets the Error page for the 403.
 *
 * Messages on the page are fixed sentences chosen here. The exception text is never shown, except for
 * a 429, whose message is ours (the sign-in throttle) and contains only the wait.
 */
final class ErrorPages
{
    /** Statuses that get the Error page; 419 is handled separately. */
    private const array PAGE_STATUSES = [
        403 => 'You do not have access to this page.',
        404 => 'The page you asked for does not exist.',
        429 => 'Too many requests. Try again shortly.',
        500 => 'Something went wrong on our side. Try again shortly.',
        503 => 'The service is temporarily unavailable. Try again shortly.',
    ];

    public function __construct(private readonly Router $router, private readonly Kernel $kernel) {}

    public function respond(Response $response, Throwable $exception, Request $request): Response
    {
        if (! $this->isUiRequest($request)) {
            return $response;
        }

        $status = $response->getStatusCode();

        if ($status === 419) {
            // A session that expired while a form was open: go back to the page with a message instead of a dead end.
            return $request->hasSession()
                ? RejectSessionlessGuests::redirectFor($request, $this->returnUrl($request))->with('status', 'The page expired. Please try again.')
                : $response;
        }

        if (! isset(self::PAGE_STATUSES[$status]) || ($status >= 500 && config('app.debug'))) {
            return $response;
        }

        $message = $exception instanceof TooManyRequestsHttpException && $exception->getMessage() !== ''
            ? $exception->getMessage()
            : self::PAGE_STATUSES[$status];

        if ($request->user() !== null) {
            // Signed-in pages are served with encrypted history; their error pages must not be the exception.
            Inertia::encryptHistory();
        }

        $page = (new ExceptionResponse($exception, $request, $response, $this->router, $this->kernel))
            ->usingMiddleware(HandleInertiaRequests::class)
            ->withSharedData()
            ->render('Error', ['status' => $status, 'message' => $message])
            ->toResponse($request);

        // The page replaces the response, so the one header a client acts on has to be carried over.
        if ($retryAfter = $response->headers->get('Retry-After')) {
            $page->headers->set('Retry-After', $retryAfter);
        }

        return $page;
    }

    /**
     * Where to send someone whose form expired: the page they came from, but only when the Referer names this
     * very host (a forged or foreign Referer must not turn the 419 into an open redirect), else the start page.
     */
    private function returnUrl(Request $request): string
    {
        $referer = parse_url((string) $request->headers->get('referer'));

        if (
            is_array($referer)
            && ($referer['host'] ?? null) === $request->getHost()
            && ($referer['port'] ?? ($request->isSecure() ? 443 : 80)) === $request->getPort()
        ) {
            $path = NextUrl::sanitise(($referer['path'] ?? '/').(isset($referer['query']) ? '?'.$referer['query'] : ''), allowAuthPages: true);

            if ($path !== null) {
                return $path;
            }
        }

        return $request->user() !== null ? route('home', absolute: false) : route('login', absolute: false);
    }

    /** An HTML (or Inertia) request for a path the UI owns. */
    private function isUiRequest(Request $request): bool
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return false;
        }

        $path = $request->path();

        if (ReservedPaths::collides($path)) {
            return false;
        }

        // App-owned prefixes (health, assets, storage, API ...) stay plain; Horizon's pages are UI and do not.
        $plain = array_diff(ReservedPaths::APP_OWNED, ['horizon']);

        return ! in_array(strtolower(explode('/', ltrim($path, '/'), 2)[0]), $plain, true);
    }
}
