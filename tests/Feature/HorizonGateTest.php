<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;

it('redirects a guest from the Horizon dashboard to the login page without a session', function () {
    $response = $this->get('/horizon')->assertRedirect('/login');

    expect($response->headers->getCookies())->toBe([]);
});

it('answers a guest API call of Horizon with 401 JSON', function () {
    $this->getJson('/horizon/api/stats')->assertUnauthorized();
});

it('forbids a signed-in user who is not an administrator', function () {
    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
    $this->actingAs(User::factory()->create())->getJson('/horizon/api/stats')->assertForbidden();
});

it('lets an administrator open the dashboard', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/horizon')->assertOk();
});

it('keeps requiring an administrator when the environment is local', function () {
    app()->detectEnvironment(fn () => 'local');

    expect(app()->environment('local'))->toBeTrue();

    $this->get('/horizon')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get('/horizon')->assertOk();
});

it('gates on is_admin and denies a missing user', function () {
    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(User::factory()->make(['is_admin' => false]))->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->make())->allows('viewHorizon'))->toBeTrue();
});

it('does not read the environment inside the provider', function () {
    $source = file_get_contents(app_path('Providers/HorizonServiceProvider.php'));

    expect($source)->not->toContain('env(');
});

it('registers an authorisation callback that follows the gate', function () {
    $check = fn () => Horizon::check(request());

    expect($check())->toBeFalse();

    $this->actingAs(User::factory()->make());
    expect($check())->toBeFalse();

    $this->actingAs(User::factory()->admin()->make());
    expect($check())->toBeTrue();
});
