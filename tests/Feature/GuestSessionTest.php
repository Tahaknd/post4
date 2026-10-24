<?php

use App\Http\Middleware\RejectSessionlessGuests;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeViteBuild;

beforeEach(function () {
    $this->publicPath = FakeViteBuild::install();
});

afterEach(function () {
    FakeViteBuild::remove($this->publicPath);
});

/** Names of every cookie the response sets. */
function setCookieNames($response): array
{
    return array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
}

it('redirects a guest from / to /login without starting a session or setting any cookie', function () {
    $response = $this->get('/')->assertRedirect('/login');

    expect(setCookieNames($response))->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();

    $response->assertCookieMissing(config('session.cookie'))->assertCookieMissing('XSRF-TOKEN');
});

it('does not remember an intended URL for a guest without a session cookie', function () {
    $this->get('/')->assertRedirect('/login')->assertSessionMissing('url.intended');
});

it('answers a guest JSON caller of a protected route with 401 and no cookie', function () {
    $response = $this->getJson('/')->assertUnauthorized();

    expect(setCookieNames($response))->toBe([]);
});

it('keeps the capture hot path and the health check free of cookies', function (string $method, string $path) {
    $response = $this->call($method, $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

    expect(setCookieNames($response))->toBe([], "{$method} {$path} set a cookie")
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
})->with([
    ['POST', '/e'],
    ['POST', '/e/'],
    ['POST', '/batch'],
    ['POST', '/capture'],
    ['POST', '/i/v0/e'],
    ['GET', '/up'],
]);

it('starts a session with a session cookie and a CSRF cookie on the login page', function () {
    $response = $this->get('/login')->assertOk();

    expect(setCookieNames($response))->toContain(config('session.cookie'), 'XSRF-TOKEN');
});

it('starts a session on the registration page while it is open', function () {
    $response = $this->get('/register')->assertOk();

    expect(setCookieNames($response))->toContain(config('session.cookie'));
});

it('starts a session for a POST, so CSRF and validation errors work', function () {
    $response = $this->post('/login', ['email' => 'x@example.test', 'password' => 'whatever']);

    expect(setCookieNames($response))->toContain(config('session.cookie'));
});

it('takes the normal path when a session cookie is present (and then sets a fresh one)', function () {
    $response = $this->withCookie(config('session.cookie'), 'a-stale-or-forged-value')->get('/');

    $response->assertRedirect('/login');
    expect(setCookieNames($response))->toContain(config('session.cookie'));
});

it('lets a signed-in user through to the home page', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertOk();
});

it('runs RejectSessionlessGuests before StartSession in the web group', function () {
    app(HttpKernel::class);
    $route = Route::getRoutes()->getByName('home');

    $middleware = app('router')->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());

    expect(array_search(RejectSessionlessGuests::class, $middleware, true))
        ->toBeInt()
        ->toBeLessThan(array_search(StartSession::class, $middleware, true));
});

it('puts session and CSRF protection on the web group only', function () {
    app(HttpKernel::class);
    $groups = app('router')->getMiddlewareGroups();

    expect($groups['web'])->toContain(StartSession::class, RejectSessionlessGuests::class);

    foreach ($groups as $name => $middleware) {
        if ($name !== 'web') {
            expect($middleware)->not->toContain(StartSession::class, "group {$name}");
        }
    }

    expect(app(HttpKernel::class)->getGlobalMiddleware())->not->toContain(StartSession::class);
});
