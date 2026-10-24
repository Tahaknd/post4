<?php

use App\Support\RateLimit\LimiterOutage;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['ratelimit.warning_interval_seconds' => 60]);
    LimiterOutage::reset();
});

afterEach(function () {
    LimiterOutage::reset();
});

it('returns the callback result and logs nothing when it succeeds', function () {
    Log::spy();

    expect(LimiterOutage::guard(fn () => 42, 'fallback'))->toBe(42);
    Log::shouldNotHaveReceived('warning');
});

it('returns the fallback and logs the exception class and message when the callback throws', function () {
    Log::spy();

    expect(LimiterOutage::guard(fn () => throw new RuntimeException('READONLY You can\'t write'), 'fallback'))->toBe('fallback');

    Log::shouldHaveReceived('warning')->once()->with(
        'capture rate limiter unavailable, failing open',
        ['exception' => RuntimeException::class, 'message' => 'READONLY You can\'t write'],
    );
});

it('catches errors as well as exceptions', function () {
    Log::spy();

    expect(LimiterOutage::guard(fn () => (fn (int $x) => $x)('not a number'), 7))->toBe(7);
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context['exception'] === TypeError::class,
    );
});

it('truncates a long message to 200 characters', function () {
    Log::spy();

    LimiterOutage::guard(fn () => throw new RuntimeException(str_repeat('x', 1000)));

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => strlen($context['message']) <= 203 && str_starts_with($context['message'], 'xxx'),
    );
});

it('logs at most one warning per interval until it is reset', function () {
    Log::spy();
    $fail = fn () => throw new RuntimeException('down');

    LimiterOutage::guard($fail);
    LimiterOutage::guard($fail);
    Log::shouldHaveReceived('warning')->once();

    LimiterOutage::reset();
    LimiterOutage::guard($fail);
    Log::shouldHaveReceived('warning')->twice();
});
