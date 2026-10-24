<?php

use App\Http\ErrorPages;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectSessionlessGuests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
            prepend: [RejectSessionlessGuests::class],
            append: [HandleInertiaRequests::class],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Error page for HTML requests on UI routes only; capture, SDK and JSON responses are untouched
        // (see App\Http\ErrorPages).
        $exceptions->respond(fn (Response $response, Throwable $e, Request $request) => app(ErrorPages::class)->respond($response, $e, $request));
    })->create();
