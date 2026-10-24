<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Route::middleware(['web', 'auth'])->group(function () {
        Route::put('/t-protected', fn () => 'x');
        Route::patch('/t-protected', fn () => 'x');
        Route::delete('/t-protected', fn () => 'x');
        Route::post('/t-protected', fn () => 'x');
        Route::get('/t-protected', fn () => 'x');
    });
    Route::middleware('web')->group(function () {
        Route::put('/t-open', fn () => 'x');
        Route::delete('/t-open', fn () => 'x');
    });
});

function sessionCookie(): string
{
    return config('session.cookie');
}

it('redirects a cookie-less guest to /login with the page they asked for', function () {
    $this->get('/horizon/dashboard')
        ->assertStatus(302)
        ->assertRedirect('/login?next=%2Fhorizon%2Fdashboard')
        ->assertHeader('Vary', 'X-Inertia');

    $this->get('/t-protected?a=1')->assertRedirect('/login?next=%2Ft-protected%3Fa%3D1');
});

it('uses 302 for GET and HEAD and 303 for every other method (no cookie)', function (string $method, int $status) {
    $response = $this->call($method, '/t-protected');

    expect($response->getStatusCode())->toBe($status)
        ->and($response->headers->get('Vary'))->toContain('X-Inertia')
        ->and($response->headers->getCookies())->toBe([]);

    // Only a GET or HEAD can be replayed, so only they carry a next.
    expect(str_contains((string) $response->headers->get('Location'), 'next='))->toBe($status === 302);
})->with([['GET', 302], ['HEAD', 302], ['POST', 303], ['PUT', 303], ['PATCH', 303], ['DELETE', 303]]);

it('behaves the same for an Inertia visit and sets the same Vary header', function (string $method, int $status) {
    $response = $this->call($method, '/t-protected', server: ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => 'v', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);

    expect($response->getStatusCode())->toBe($status)
        ->and($response->headers->get('Vary'))->toContain('X-Inertia');
})->with([['GET', 302], ['PUT', 303], ['PATCH', 303], ['DELETE', 303]]);

it('sends a guest with a stale session cookie to the same login URL', function () {
    $this->withCookie(sessionCookie(), Str::random(40))
        ->get('/horizon')
        ->assertRedirect('/login?next=%2Fhorizon');
});

it('answers an expired form (419) with 303 to the page it came from, for every unsafe method', function (string $method) {
    app()->detectEnvironment(fn () => 'production');

    $this->call($method, '/t-open', server: ['HTTP_REFERER' => url('/login?next=%2Fhorizon')])
        ->assertStatus(303)
        ->assertRedirect('/login?next=%2Fhorizon')
        ->assertHeader('Vary', 'X-Inertia')
        ->assertSessionHas('status', 'The page expired. Please try again.');
})->with(['PUT', 'DELETE']);

it('never follows a Referer to another host after a 419', function (string $referer) {
    app()->detectEnvironment(fn () => 'production');

    $this->call('PUT', '/t-open', server: ['HTTP_REFERER' => $referer])
        ->assertStatus(303)
        ->assertRedirect('/login');
})->with([
    'other host' => ['https://evil.example/login'],
    'host as userinfo' => ['http://localhost@evil.example/x'],
    'other port' => ['http://localhost:9999/login'],
    'no referer' => [''],
    'garbage' => ['http://'],
    'capture path on this host' => ['http://localhost:8000/e/x'],
]);

it('sends a signed-in user home after a 419 without a usable Referer', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->actingAs(User::factory()->create())
        ->withSession([])
        ->call('PUT', '/t-open', server: ['HTTP_REFERER' => 'https://evil.example/'])
        ->assertRedirect('/');
});

it('answers an unknown path without a session cookie with a 404 page and no cookie', function () {
    $response = $this->get('/no-such-page')->assertNotFound();

    expect($response->headers->getCookies())->toBe([]);
});

it('answers an unknown path for a signed-in user (with a session) inside the session', function () {
    $user = User::factory()->create(['name' => 'Ada']);

    $response = $this->actingAs($user)
        ->withCookie(sessionCookie(), Str::random(40))
        ->get('/no-such-page');

    $response->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('auth.user.name', 'Ada'));

    expect($response->viewData('page'))->toHaveKey('encryptHistory', true)
        ->and(collect($response->headers->getCookies())->map->getName()->all())->toContain(sessionCookie());
});

it('encrypts the history of a 403 for a signed-in user and not for a guest', function () {
    $response = $this->actingAs(User::factory()->create())->get('/horizon');

    expect($response->viewData('page'))->toHaveKey('encryptHistory', true);
});

it('never starts a session for a capture or SDK path, even with a cookie', function (string $path) {
    $response = $this->withCookie(sessionCookie(), Str::random(40))->get($path);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->getContent())->not->toContain('data-page');
})->with(['/e/x', '/batch/x', '/capture/x', '/i/v0/x', '/array/abc/config', '/flags', '/decide']);
