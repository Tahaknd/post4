<?php

use App\Domain\Capture\FailureLimiter;
use App\Domain\Capture\LimiterOutage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config(['capture.auth_fail_limit' => 3, 'capture.auth_fail_window_seconds' => 60]);
    $this->limiter = new FailureLimiter;
});

afterEach(function () {
    LimiterOutage::reset();
});

it('uses an IPv4 address as its own key', function () {
    expect($this->limiter->key('203.0.113.7'))->toBe('capture-auth-fail:203.0.113.7');
});

it('maps an IPv4-mapped IPv6 address to the IPv4 key', function (string $mapped) {
    expect($this->limiter->key($mapped))->toBe($this->limiter->key('203.0.113.7'));
})->with(['::ffff:203.0.113.7', '::FFFF:cb00:7107', '0:0:0:0:0:ffff:203.0.113.7']);

it('shares one budget across a /64 and separates different /64 prefixes', function () {
    $a = $this->limiter->key('2001:db8:1:2:aaaa:bbbb:cccc:dddd');
    $b = $this->limiter->key('2001:db8:1:2::1');
    $other = $this->limiter->key('2001:db8:1:3::1');

    expect($a)->toBe($b)->and($a)->not->toBe($other);
    // An unrelated IPv4 or the unknown bucket never collides with an IPv6 prefix key.
    expect($a)->not->toBe($this->limiter->key('unknown'));
});

it('only treats the ::ffff:0:0/96 range as IPv4-mapped', function () {
    // Same /64 as ::1, and not an IPv4 key; an address with other leading bits is no mapped address either.
    expect($this->limiter->key('::1'))->toBe($this->limiter->key('::2'))
        ->and($this->limiter->key('::203.0.113.7'))->toStartWith('capture-auth-fail:v6-')
        ->and($this->limiter->key('1::ffff:203.0.113.7'))->toStartWith('capture-auth-fail:v6-');
});

it('maps empty and unparseable ips to one constant key', function (string $ip) {
    expect($this->limiter->key($ip))->toBe('capture-auth-fail:unknown');
})->with(['', 'not-an-ip', '999.1.1.1', '1.2.3', 'fe80::1%eth0', "1.2.3.4\0", "\0"]);

it('is not blocked until the limit is reached, then reports a retry delay', function () {
    expect($this->limiter->blockedFor('203.0.113.7'))->toBeNull();

    foreach (range(1, 3) as $_) {
        $this->limiter->record('203.0.113.7');
    }

    expect(RateLimiter::attempts('capture-auth-fail:203.0.113.7'))->toBe(3)
        ->and($this->limiter->blockedFor('203.0.113.7'))->toBeInt()->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60)
        ->and($this->limiter->blockedFor('203.0.113.8'))->toBeNull();
});

it('blocks every address of the same /64 once one of them used the budget', function () {
    foreach (['2001:db8:1:2::1', '2001:db8:1:2::2', '2001:db8:1:2::ffff'] as $ip) {
        $this->limiter->record($ip);
    }

    expect($this->limiter->blockedFor('2001:db8:1:2:1234::9'))->toBeInt()
        ->and($this->limiter->blockedFor('2001:db8:1:3::1'))->toBeNull();
});

it('warns exactly once, when the limit is reached', function () {
    Log::spy();

    foreach (range(1, 6) as $_) {
        $this->limiter->record('203.0.113.7');
    }

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context === ['limiter_key' => 'capture-auth-fail:203.0.113.7', 'limit' => 3],
    );
});

it('fails open and warns once when the cache is unavailable', function () {
    Log::spy();
    RateLimiter::shouldReceive('tooManyAttempts')->andThrow(new RuntimeException('redis down'));
    RateLimiter::shouldReceive('hit')->andThrow(new RuntimeException('redis down'));

    expect($this->limiter->blockedFor('203.0.113.7'))->toBeNull();
    $this->limiter->record('203.0.113.7');
    expect($this->limiter->blockedFor('203.0.113.7'))->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context === ['exception' => RuntimeException::class, 'message' => 'redis down'],
    );
});

it('does not fail the request when the limit warning cannot be written', function () {
    Log::shouldReceive('warning')->with('capture failure limit reached, blocking caller', Mockery::type('array'))
        ->andThrow(new RuntimeException('logger down'));
    Log::shouldReceive('warning')->once()->with('capture rate limiter unavailable, failing open', Mockery::type('array'));

    foreach (range(1, 3) as $_) {
        $this->limiter->record('203.0.113.7');
    }

    expect($this->limiter->blockedFor('203.0.113.7'))->toBeInt();
});
