<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['app.debug' => false]);
});

it('renders the Error page for an unknown HTML path', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('message', 'The page you asked for does not exist.')
            ->where('app.name', config('app.name'))
            ->where('auth.user', null));
});

it('answers an Inertia visit to an unknown page with a JSON Error page and the 404 status', function () {
    $this->get('/no-such-page', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'whatever'])
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Error')
        ->assertJsonPath('props.status', 404);
});

it('keeps JSON callers on JSON', function () {
    $this->getJson('/no-such-page')->assertNotFound()->assertHeaderMissing('X-Inertia');
    $this->getJson('/api/nothing')->assertNotFound()->assertHeaderMissing('X-Inertia');
});

it('never renders a page for capture and SDK paths', function (string $method, string $path) {
    $response = $this->call($method, $path, [], [], [], ['HTTP_ACCEPT' => 'text/html']);

    expect($response->getContent())->not->toContain('data-page')->not->toContain('<div id="app">');
})->with([
    ['GET', '/e/nothing'],
    ['GET', '/batch/nothing'],
    ['GET', '/capture/nothing'],
    ['GET', '/i/v0/nothing'],
    ['GET', '/flags'],
    ['GET', '/decide/x'],
    ['GET', '/array/abc/config'],
    ['GET', '/s/x'],
    ['GET', '/static/x.js'],
]);

it('keeps capture errors JSON even for an HTML-accepting caller', function () {
    $response = $this->call('POST', '/e/', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], '{"event":"x"}');

    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->getStatusCode())->toBeIn([401, 400]);
});

it('keeps health and asset paths plain', function (string $path) {
    $response = $this->get($path);

    expect($response->getContent())->not->toContain('data-page');
})->with(['/build/assets/missing.js', '/storage/missing.png', '/_inertia/x', '/up']);

it('renders the Error page for a 403 on an HTML request and plain JSON on a JSON one', function () {
    $this->actingAs(User::factory()->create())
        ->get('/horizon')
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->where('message', 'You do not have access to this page.'));

    $this->actingAs(User::factory()->create())->getJson('/horizon/api/stats')->assertForbidden()->assertHeaderMissing('X-Inertia');
});

it('renders the Error page for a 429 on a plain form post, with the wait and Retry-After', function () {
    User::factory()->create(['email' => 'ada@example.test']);

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'ada@example.test', 'password' => 'wrong wrong wrong']);
    }

    $response = $this->post('/login', ['email' => 'ada@example.test', 'password' => 'wrong wrong wrong']);

    $response->assertStatus(429)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 429));

    expect($response->viewData('page')['props']['message'])->toStartWith('Too many sign-in attempts. Try again in ')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('hides the exception text of a 500 and shows the Error page', function () {
    Route::middleware('web')->get('/boom', fn () => throw new RuntimeException('SECRET internal detail'));

    $response = $this->get('/boom')->assertStatus(500);

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Error')
        ->where('status', 500)
        ->where('message', 'Something went wrong on our side. Try again shortly.'));

    expect($response->getContent())->not->toContain('SECRET');
});

it('leaves the debug page alone for a 500 when debugging is on', function () {
    config(['app.debug' => true]);
    Route::middleware('web')->get('/boom', fn () => throw new RuntimeException('SECRET internal detail'));

    $response = $this->get('/boom')->assertStatus(500);

    expect($response->getContent())->not->toContain('<div id="app">');
});

it('redirects back with a message when the page expired (419)', function () {
    // CSRF verification is skipped while running unit tests; any other environment switches it on.
    app()->detectEnvironment(fn () => 'production');

    $this->from(url('/login'))
        ->post('/login', ['email' => 'ada@example.test', 'password' => 'whatever'])
        ->assertStatus(303)
        ->assertRedirect('/login')
        ->assertSessionHas('status', 'The page expired. Please try again.');
});

it('does not turn a 419 on a JSON request into a redirect', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->postJson('/login', ['email' => 'ada@example.test', 'password' => 'whatever'])->assertStatus(419);
});

it('encrypts the history of signed-in pages', function () {
    expect($this->actingAs(User::factory()->create())->get('/')->viewData('page'))->toHaveKey('encryptHistory', true);
});

it('does not encrypt the history of the guest pages', function () {
    expect($this->get('/login')->viewData('page'))->not->toHaveKey('encryptHistory');
});
