<?php

use App\Domain\Auth\LoginThrottle;
use App\Http\Controllers\Auth\LoginController;
use App\Models\User;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FailingCacheStore;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ada@example.test', 'password' => 'correct horse battery']);
});

afterEach(function () {
    LimiterOutage::reset();
});

function login(array $overrides = [])
{
    return test()->post('/login', array_merge(['email' => 'ada@example.test', 'password' => 'correct horse battery'], $overrides));
}

it('shows the login page to guests and tells whether registration is open', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login')->where('canRegister', false));

    User::query()->delete();

    $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('canRegister', true));
});

it('signs a user in and redirects to the home page', function () {
    login()->assertRedirect('/');

    expect(Auth::id())->toBe($this->user->id);
});

it('matches the email case-insensitively', function () {
    login(['email' => '  ADA@Example.TEST '])->assertRedirect('/');

    expect(Auth::id())->toBe($this->user->id);
});

it('sends the user back to the page they asked for through next', function () {
    login(['next' => '/horizon'])->assertRedirect('/horizon');
});

it('falls back to the home page for a next that is not a same-origin path', function (string $next) {
    login(['next' => $next])->assertRedirect('/');
})->with([
    'absolute url' => ['https://evil.example/'],
    'protocol relative' => ['//evil.example/x'],
    'backslash host' => ['/\\evil.example'],
    'javascript' => ['javascript:alert(1)'],
    'tab smuggled slash' => ["/\t/evil.example"],
    'capture path' => ['/e/x'],
    'login itself' => ['/login'],
    'too long' => ['/'.str_repeat('a', 250)],
]);

it('does not set a remember-me cookie', function () {
    login(['remember' => true])->assertCookieMissing(Auth::guard('web')->getRecallerName());

    expect($this->user->fresh()->remember_token)->toBe($this->user->remember_token);
});

it('rejects a wrong password with the generic message', function () {
    login(['password' => 'wrong wrong wrong'])
        ->assertSessionHasErrors(['email' => LoginController::FAILED_MESSAGE]);

    expect(Auth::check())->toBeFalse();
});

it('gives an unknown email exactly the same answer as a wrong password', function () {
    $unknown = login(['email' => 'nobody@example.test']);
    $wrong = login(['password' => 'wrong wrong wrong']);

    $unknown->assertSessionHasErrors(['email' => LoginController::FAILED_MESSAGE]);
    $wrong->assertSessionHasErrors(['email' => LoginController::FAILED_MESSAGE]);

    expect($unknown->getStatusCode())->toBe($wrong->getStatusCode())
        ->and($unknown->headers->get('Location'))->toBe($wrong->headers->get('Location'));
});

it('hashes a password even when the email is unknown (no timing oracle)', function () {
    Hash::partialMock()->shouldReceive('check')->once()->andReturn(false);

    login(['email' => 'nobody@example.test'])->assertSessionHasErrors('email');
});

it('requires both fields', function () {
    login(['password' => ''])->assertSessionHasErrors('password');
    login(['email' => ''])->assertSessionHasErrors('email');
});

it('moves the session to a new id on sign-in and destroys the old one (no fixation)', function () {
    $before = Str::random(40);
    $handler = app('session')->driver()->getHandler();
    $handler->write($before, json_encode(['planted' => 'by the attacker']));

    $this->withCookie(config('session.cookie'), $before);
    login()->assertRedirect('/');

    expect(session()->getId())->not->toBe($before)
        ->and(strlen(session()->getId()))->toBe(40)
        ->and($handler->read($before))->toBe('');
});

it('signs out, invalidates the session and asks the browser to clear its history', function () {
    $this->actingAs($this->user)->withSession(['secret' => 'data']);
    $this->startSession();
    $oldToken = session()->token();
    $oldId = session()->getId();

    $this->post('/logout')->assertRedirect('/login');

    expect(Auth::check())->toBeFalse()
        ->and(session()->getId())->not->toBe($oldId)
        ->and(session()->token())->not->toBe($oldToken)
        ->and(session()->has('secret'))->toBeFalse()
        ->and(session()->get('inertia.clear_history'))->toBeTrue();
});

it('puts the clear-history flag on the page that follows sign-out', function () {
    $this->actingAs($this->user);
    $this->post('/logout');

    expect($this->get('/login')->viewData('page'))->toHaveKey('clearHistory', true);
});

it('redirects a signed-in user away from the login page', function () {
    $this->actingAs($this->user)->get('/login')->assertRedirect('/');
});

it('blocks the sixth failed attempt for the same email and ip with 429 and Retry-After', function () {
    foreach (range(1, 5) as $attempt) {
        login(['password' => 'wrong wrong wrong'])->assertSessionHasErrors('email');
    }

    $blocked = login(['password' => 'wrong wrong wrong']);

    $blocked->assertStatus(429);
    expect((int) $blocked->headers->get('Retry-After'))->toBeBetween(1, 900);

    // Even the right password is refused while blocked.
    login()->assertStatus(429);
    expect(Auth::check())->toBeFalse();
});

it('blocks only the failing (email, ip) pair and lets the same account sign in from another address', function () {
    foreach (range(1, 5) as $attempt) {
        login(['password' => 'wrong wrong wrong']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/login', ['email' => 'ada@example.test', 'password' => 'correct horse battery'])
        ->assertRedirect('/');
});

it('blocks an address that fails against many accounts', function () {
    config(['auth.login_throttle.ip_limit' => 3]);

    foreach (['a', 'b', 'c'] as $name) {
        login(['email' => "{$name}@example.test", 'password' => 'wrong wrong wrong'])->assertSessionHasErrors('email');
    }

    login(['email' => 'd@example.test', 'password' => 'wrong wrong wrong'])->assertStatus(429);
});

it('counts failures for unknown emails like any other', function () {
    foreach (range(1, 5) as $attempt) {
        login(['email' => 'nobody@example.test']);
    }

    login(['email' => 'nobody@example.test'])->assertStatus(429);
});

it('forgives the email-and-ip failures after a successful sign-in', function () {
    foreach (range(1, 4) as $attempt) {
        login(['password' => 'wrong wrong wrong']);
    }

    login()->assertRedirect('/');
    Auth::logout();

    foreach (range(1, 4) as $attempt) {
        login(['password' => 'wrong wrong wrong'])->assertSessionHasErrors('email');
    }
});

it('releases the block when the window has passed', function () {
    $this->travelTo(now());
    foreach (range(1, 5) as $attempt) {
        login(['password' => 'wrong wrong wrong']);
    }
    login()->assertStatus(429);

    $this->travel(901)->seconds();

    login()->assertRedirect('/');
});

it('keeps no email address in the throttle keys', function () {
    $keys = [(new LoginThrottle)->pairKey('Ada@Example.test', '198.51.100.7'), (new LoginThrottle)->ipKey('198.51.100.7')];

    expect(implode(' ', $keys))->not->toContain('ada')->not->toContain('example')
        ->and((new LoginThrottle)->pairKey('ADA@example.test ', '198.51.100.7'))->toBe($keys[0])
        ->and((new LoginThrottle)->ipKey('::ffff:198.51.100.7'))->toBe($keys[1]);
});

it('fails open and warns once when the limiter store is unavailable', function () {
    Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
    app()->instance(CacheRateLimiter::class, new CacheRateLimiter(Cache::repository(new FailingCacheStore)));
    RateLimiter::clearResolvedInstance(CacheRateLimiter::class);
    Log::spy();

    login(['password' => 'wrong wrong wrong'])->assertSessionHasErrors('email');
    login(['password' => 'wrong wrong wrong'])->assertSessionHasErrors('email');
    login()->assertRedirect('/');

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $message === 'login rate limiter unavailable, failing open'
            && $context === ['exception' => RuntimeException::class, 'message' => 'cache store unavailable'],
    );
});

it('blocks an email across addresses once its own budget is spent, without locking the account afterwards', function () {
    config(['auth.login_throttle.email_limit' => 3, 'auth.login_throttle.email_ip_limit' => 50, 'auth.login_throttle.ip_limit' => 50]);

    foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => 'ada@example.test', 'password' => 'wrong wrong wrong'])
            ->assertSessionHasErrors('email');
    }

    // A fourth address is refused, even with the right password: the email is being rated.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])
        ->post('/login', ['email' => 'ada@example.test', 'password' => 'correct horse battery'])
        ->assertStatus(429);

    // Another account on the same addresses is untouched.
    User::factory()->create(['email' => 'bob@example.test', 'password' => 'correct horse battery']);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])
        ->post('/login', ['email' => 'bob@example.test', 'password' => 'correct horse battery'])
        ->assertRedirect('/');
    Auth::logout();

    // Rate limit, not lockout: the next window starts clean.
    $this->travel(901)->seconds();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])
        ->post('/login', ['email' => 'ada@example.test', 'password' => 'correct horse battery'])
        ->assertRedirect('/');
});

it('does not let successful sign-ins use up the per-address or per-email budgets', function () {
    config(['auth.login_throttle.ip_limit' => 2, 'auth.login_throttle.email_limit' => 2]);

    foreach (range(1, 6) as $attempt) {
        login()->assertRedirect('/');
        Auth::logout();
    }

    $key = fn (string $name, string ...$args) => (new LoginThrottle)->{$name}(...$args);

    expect(RateLimiter::attempts($key('ipKey', '127.0.0.1')))->toBe(0)
        ->and(RateLimiter::attempts($key('emailKey', 'ada@example.test')))->toBe(0);
});

it('answers a throttled Inertia form with a validation error on email and keeps the 429 for other clients', function () {
    foreach (range(1, 5) as $attempt) {
        login(['password' => 'wrong wrong wrong']);
    }

    $version = hash_file('md5', $this->publicPath.'/build/manifest.json');

    $this->from('/login')
        ->post('/login', ['email' => 'ada@example.test', 'password' => 'wrong wrong wrong'], ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toStartWith('Too many sign-in attempts. Try again in ');

    $json = $this->postJson('/login', ['email' => 'ada@example.test', 'password' => 'wrong wrong wrong'])->assertStatus(429);
    expect((int) $json->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('counts the attempt before the password is checked', function () {
    config(['auth.login_throttle.email_ip_limit' => 1]);
    $throttle = new LoginThrottle;

    // The first caller proceeds, and by the time it verifies the next one is already refused.
    expect($throttle->hit('ada@example.test', '198.51.100.1'))->toBeNull()
        ->and($throttle->hit('ada@example.test', '198.51.100.1'))->toBeInt();
});

it('runs exactly one password hash check whether or not the email exists', function (string $email) {
    $hasher = Hash::partialMock();
    $hasher->shouldReceive('make')->andReturn('$2y$04$abcdefghijklmnopqrstuuabcdefghijklmnopqrstuvwxyz01234');
    $hasher->shouldReceive('check')->once()->andReturn(false);

    login(['email' => $email, 'password' => 'wrong wrong wrong'])->assertSessionHasErrors('email');
})->with(['existing user, wrong password' => ['ada@example.test'], 'unknown email' => ['nobody@example.test']]);
