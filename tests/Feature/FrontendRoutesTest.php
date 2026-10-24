<?php

use App\Http\ReservedPaths;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The `export const NAME_URL = '/path';` constants of resources/js/routes.js.
 *
 * @return array<string, string>
 */
function frontendUrlConstants(): array
{
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/routes.js');

    preg_match_all("/^export const ([A-Z_]+_URL) = '([^']*)';/m", $source, $matches, PREG_SET_ORDER);

    return array_column($matches, 2, 1);
}

/** Whether the router would dispatch a request with this method to a registered route. */
function routeExists(string $method, string $path): bool
{
    app(HttpKernel::class);

    try {
        // The catch-all 404 route of the web group matches every GET; it is not a page.
        return ! Route::getRoutes()->match(Request::create($path, $method))->isFallback;
    } catch (HttpExceptionInterface) {
        return false;
    }
}

it('lists the URL constants the UI uses', function () {
    expect(frontendUrlConstants())->toBe([
        'HOME_URL' => '/',
        'LOGIN_URL' => '/login',
        'REGISTER_URL' => '/register',
        'LOGOUT_URL' => '/logout',
        'PROJECTS_URL' => '/projects',
        'PROJECTS_CREATE_URL' => '/projects/create',
        'HORIZON_URL' => '/horizon',
    ]);
});

it('resolves every URL constant in routes.js to a registered route', function (string $name, string $path) {
    $resolves = routeExists('GET', $path) || routeExists('POST', $path);

    expect($resolves)->toBeTrue("{$name} = {$path} matches no registered route");
})->with(fn () => array_map(fn ($path, $name) => [$name, $path], frontendUrlConstants(), array_keys(frontendUrlConstants())));

it('keeps the logout constant on a POST route and the page constants on GET routes', function () {
    $urls = frontendUrlConstants();

    expect(routeExists('POST', $urls['LOGOUT_URL']))->toBeTrue()
        ->and(routeExists('GET', $urls['LOGOUT_URL']))->toBeFalse()
        ->and(routeExists('GET', $urls['HOME_URL']))->toBeTrue()
        ->and(routeExists('GET', $urls['LOGIN_URL']))->toBeTrue()
        ->and(routeExists('GET', $urls['REGISTER_URL']))->toBeTrue();
});

it('keeps capture and SDK paths out of routes.js', function (string $name, string $path) {
    expect(ReservedPaths::collides($path))->toBeFalse("{$name} points into a capture or SDK path");
})->with(fn () => array_map(fn ($path, $name) => [$name, $path], frontendUrlConstants(), array_keys(frontendUrlConstants())));

it('detects a constant that matches no route', function () {
    expect(routeExists('GET', '/definitely-not-a-route'))->toBeFalse()
        ->and(routeExists('POST', '/login-typo'))->toBeFalse();
});

it('has no id-templated URL helper: URLs with parameters come from the server', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/routes.js');

    expect($source)->not->toContain('projectUrl')->not->toContain('${');
});
