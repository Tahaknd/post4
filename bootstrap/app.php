<?php

use App\Http\ApiErrors;
use App\Http\ErrorPages;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoStoreResponses;
use App\Http\Middleware\RejectMalformedUtf8;
use App\Http\Middleware\RejectSessionlessGuests;
use App\Http\Middleware\ThrottleUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // No session, cookie or CSRF middleware for the SDK capture endpoints.
            Route::middleware([])->group(base_path('routes/capture.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only the web group: the capture routes (routes/capture.php) run with no middleware at all.
        // RejectSessionlessGuests goes first, ahead of StartSession: a visitor without a session cookie who
        // asks for a page that needs a sign-in is redirected without ever getting a session or a cookie.
        $middleware->web(
            prepend: [RejectMalformedUtf8::class, RejectSessionlessGuests::class],
            append: [HandleInertiaRequests::class],
        );

        // `throttle.user:{family}`: per-user budgets (config/ratelimit.php, user_throttles).
        $middleware->alias(['throttle.user' => ThrottleUser::class, 'api.no-store' => NoStoreResponses::class]);

        // The JSON API takes strings exactly as sent: an event name keeps its spaces, `equals ''` keeps its empty
        // value (TrimStrings and ConvertEmptyStringsToNull would silently change the definition being cached).
        TrimStrings::skipWhen(fn (Request $request) => ApiErrors::isApiRequest($request));
        ConvertEmptyStringsToNull::skipWhen(fn (Request $request) => ApiErrors::isApiRequest($request));

        // A guest with a stale session cookie reaches Authenticate: same /login?next= target as the cookie-less path.
        $middleware->redirectGuestsTo(fn (Request $request) => RejectSessionlessGuests::loginUrl($request));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never flash these into the session after a failed validation: a project name is not a secret, but the
        // confirmation text is typed into a destructive dialog and neither belongs in a flashed copy of the input.
        $exceptions->dontFlash(['name', 'confirm_name']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Error page for HTML requests on UI routes only; capture, SDK and JSON responses are untouched
        // (see App\Http\ErrorPages).
        // Fixed JSON for /api/* first (ApiErrors), the Error page for HTML requests on UI routes second (ErrorPages
        // skips /api/*).
        $exceptions->respond(fn (Response $response, Throwable $e, Request $request) => app(ErrorPages::class)->respond(ApiErrors::respond($response, $e, $request), $e, $request));
    })->create();
