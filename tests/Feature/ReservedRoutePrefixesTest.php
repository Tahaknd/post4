<?php

use App\Http\Controllers\CaptureController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\ReservedPaths;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/** The router only learns the middleware groups once the HTTP kernel exists (normally on the first request). */
function bootHttpKernel(): void
{
    app(HttpKernel::class);
}

/**
 * Routes that run the web group (session, cookies, CSRF, Inertia). Capture routes, /up and the
 * storage disk run without it.
 *
 * @return list<RoutingRoute>
 */
function webGroupRoutes(): array
{
    bootHttpKernel();

    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RoutingRoute $route) => in_array(
            StartSession::class,
            app('router')->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()),
            true,
        ),
    ));
}

/**
 * URIs of web-group routes that could shadow or be mistaken for a capture or SDK endpoint: the first
 * segment is reserved, or it is a route parameter (`/{project}/...` matches `/e` too).
 *
 * @return list<string>
 */
function webRoutesCollidingWithReservedPaths(): array
{
    $collisions = [];

    foreach (webGroupRoutes() as $route) {
        $first = explode('/', ltrim($route->uri(), '/'), 2)[0];
        $wildcard = str_starts_with($first, '{') && ! $route->isFallback;

        if (ReservedPaths::collides($route->uri()) || $wildcard) {
            $collisions[] = $route->uri();
        }
    }

    return $collisions;
}

/**
 * URIs of web-group routes that take a name the application or a package already owns. The owning
 * package's own routes are allowed: Horizon (`horizon.*`) and Inertia DevTools (`inertia.*`).
 *
 * @return list<string>
 */
function webRoutesTakingAppOwnedNames(): array
{
    $taken = [];

    foreach (webGroupRoutes() as $route) {
        if (preg_match('/^(horizon|inertia)\./', (string) $route->getName())) {
            continue;
        }

        if (ReservedPaths::takesAppOwnedName($route->uri())) {
            $taken[] = $route->uri();
        }
    }

    return $taken;
}

it('keeps every web route out of the reserved capture and SDK prefixes', function () {
    expect(webGroupRoutes())->not->toBeEmpty()
        ->and(webRoutesCollidingWithReservedPaths())->toBe([]);
});

it('detects a web route under a reserved prefix', function (string $uri) {
    Route::middleware('web')->get($uri, fn () => 'ui');

    expect(webRoutesCollidingWithReservedPaths())->toContain(ltrim($uri, '/'));
})->with([
    '/e',
    '/e/dashboard',
    '/batch',
    '/capture/settings',
    '/i/anything',
    '/array/config',
    '/decide',
    '/flags',
    '/{project}/insights',
]);

it('does not flag web routes that merely start with the same letters', function () {
    Route::middleware('web')->get('/events', fn () => 'ui');
    Route::middleware('web')->get('/insights/trends', fn () => 'ui');
    Route::middleware('web')->get('/flags-overview', fn () => 'ui');

    expect(webRoutesCollidingWithReservedPaths())->toBe([]);
});

it('classifies reserved paths by their first segment', function () {
    expect(ReservedPaths::collides('/e'))->toBeTrue()
        ->and(ReservedPaths::collides('e/x'))->toBeTrue()
        ->and(ReservedPaths::collides('/i/v0/e'))->toBeTrue()
        ->and(ReservedPaths::collides('/E'))->toBeTrue()
        ->and(ReservedPaths::collides('/array/abc/config'))->toBeTrue()
        ->and(ReservedPaths::collides('/'))->toBeFalse()
        ->and(ReservedPaths::collides('/events'))->toBeFalse()
        ->and(ReservedPaths::collides('/projects/1'))->toBeFalse();
});

it('registers the capture routes only under reserved prefixes and without middleware', function () {
    $capture = array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RoutingRoute $route) => ($route->getAction('controller') ?? '') === CaptureController::class,
    ));

    expect($capture)->not->toBeEmpty();

    foreach ($capture as $route) {
        expect(ReservedPaths::collides($route->uri()))->toBeTrue("{$route->uri()} is not covered by ReservedPaths::SEGMENTS")
            ->and($route->gatherMiddleware())->toBe([], "{$route->uri()} must stay middleware-free");
    }
});

it('registers HandleInertiaRequests on the web group only', function () {
    bootHttpKernel();

    $groups = app('router')->getMiddlewareGroups();

    expect($groups['web'])->toContain(HandleInertiaRequests::class);

    foreach ($groups as $name => $middleware) {
        if ($name !== 'web') {
            expect($middleware)->not->toContain(HandleInertiaRequests::class, "group {$name}");
        }
    }

    expect(app(HttpKernel::class)->getGlobalMiddleware())->not->toContain(HandleInertiaRequests::class);
});

it('keeps every UI route off the names the application and its packages own', function () {
    expect(webRoutesTakingAppOwnedNames())->toBe([]);
});

it('detects a web route that takes an app-owned name', function (string $uri) {
    Route::middleware('web')->get($uri, fn () => 'ui');

    expect(webRoutesTakingAppOwnedNames())->toContain(ltrim($uri, '/'));
})->with(['/up', '/build/page', '/storage', '/horizon/custom', '/_inertia/x', '/api/insights']);

it('keeps the capture segments and the app-owned names disjoint', function () {
    expect(array_intersect(ReservedPaths::SEGMENTS, ReservedPaths::APP_OWNED))->toBe([])
        ->and(ReservedPaths::SEGMENTS)->toContain('s', 'static', 'engage')
        ->and(ReservedPaths::takesAppOwnedName('/Build/x'))->toBeTrue()
        ->and(ReservedPaths::takesAppOwnedName('/builder'))->toBeFalse();
});
