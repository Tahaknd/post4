<?php

use App\Actions\Users\CreateUser;
use App\Actions\Users\RegisterFirstUser;
use App\Actions\Users\RegistrationClosed;
use App\Http\Middleware\EnsureRegistrationOpen;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeViteBuild;

beforeEach(function () {
    $this->publicPath = FakeViteBuild::install();
});

afterEach(function () {
    FakeViteBuild::remove($this->publicPath);
});

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'First Owner',
        'email' => 'Owner@Example.TEST',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ], $overrides);
}

it('shows the registration page while no user exists', function () {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Register'));
});

it('makes the first registered user an administrator and signs them in', function () {
    $this->post('/register', registrationPayload())->assertRedirect('/');

    $user = User::sole();

    expect($user->is_admin)->toBeTrue()
        ->and($user->name)->toBe('First Owner')
        ->and($user->email)->toBe('owner@example.test')
        ->and(Auth::id())->toBe($user->id);
});

it('stores a hash of the password, never the password', function () {
    $this->post('/register', registrationPayload());

    $stored = User::sole()->password;

    expect($stored)->not->toContain('correct horse')
        ->and(Hash::isHashed($stored))->toBeTrue()
        ->and(Hash::check('correct horse battery', $stored))->toBeTrue();
});

it('closes registration after the first user (GET and POST both redirect to the login page)', function () {
    User::factory()->create();

    $this->get('/register')->assertRedirect('/login')->assertSessionHas('status', 'Registration is closed.');

    $this->post('/register', registrationPayload())->assertRedirect('/login');

    expect(User::count())->toBe(1);
});

it('never lets a second registration create an administrator or another user', function () {
    $this->post('/register', registrationPayload())->assertRedirect('/');
    Auth::logout();

    $this->post('/register', registrationPayload(['email' => 'second@example.test', 'is_admin' => true]))
        ->assertRedirect('/login');

    expect(User::count())->toBe(1)
        ->and(User::where('email', 'second@example.test')->exists())->toBeFalse();
});

it('refuses the second of two first registrations (the action re-checks under its lock)', function () {
    $action = app(RegisterFirstUser::class);

    $first = $action->handle('First', 'first@example.test', 'correct horse battery');

    expect($first->is_admin)->toBeTrue()
        ->and(fn () => $action->handle('Second', 'second@example.test', 'correct horse battery'))
        ->toThrow(RegistrationClosed::class)
        ->and(User::count())->toBe(1)
        ->and(User::where('is_admin', true)->count())->toBe(1);
});

it('treats a registration that loses the race like a closed one', function () {
    // The middleware's check passed on stale information (simulated by skipping it); the action then
    // finds the account that someone else committed first.
    User::factory()->admin()->create();

    $this->withoutMiddleware(EnsureRegistrationOpen::class)
        ->post('/register', registrationPayload(['email' => 'late@example.test']))
        ->assertRedirect('/login')
        ->assertSessionHas('status', 'Registration is closed.');

    expect(User::count())->toBe(1)->and(Auth::check())->toBeFalse();
});

it('creates ordinary users that are not administrators through CreateUser', function () {
    $user = app(CreateUser::class)->handle('Member', 'member@example.test', 'correct horse battery');

    expect($user->is_admin)->toBeFalse();
});

it('validates the registration fields', function (array $overrides, string $field) {
    $this->post('/register', registrationPayload($overrides))->assertSessionHasErrors($field);

    expect(User::count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'missing email' => [['email' => ''], 'email'],
    'malformed email' => [['email' => 'not-an-email'], 'email'],
    'eleven character password' => [['password' => 'elevenchars', 'password_confirmation' => 'elevenchars'], 'password'],
    'unconfirmed password' => [['password_confirmation' => 'something else entirely'], 'password'],
    'password longer than bcrypt reads' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password'],
]);

it('accepts a twelve character password', function () {
    $this->post('/register', registrationPayload(['password' => 'twelve chars', 'password_confirmation' => 'twelve chars']))
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');
});

it('redirects a signed-in user away from the registration page', function () {
    $this->actingAs(User::factory()->create())->get('/register')->assertRedirect('/');
});

it('does not trust is_admin from the request body', function () {
    $this->post('/register', registrationPayload(['is_admin' => false]))->assertRedirect('/');

    expect(User::sole()->is_admin)->toBeTrue();
});
