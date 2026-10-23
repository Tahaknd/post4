<?php

use App\Domain\Capture\LimiterOutage;
use App\Domain\Capture\ProjectRateLimiter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Support\FailingCacheStore;

const RATE_TS = 1_792_670_410; // 2026-10-22 12:00:10 UTC, 50 s before the window ends

function rateCounter(int $projectId, int $ts = RATE_TS): int
{
    return (int) Cache::get("capture-rate:{$projectId}:".intdiv($ts, 60));
}

beforeEach(function () {
    config(['capture.project_events_per_minute' => 5, 'capture.max_batch_size' => 4]);
    $this->limiter = new ProjectRateLimiter(fn (): int => 0);
});

afterEach(function () {
    LimiterOutage::reset();
});

it('allows charges within the limit and counts them', function () {
    expect($this->limiter->reserve(1, 3, RATE_TS))->toBeNull()
        ->and($this->limiter->reserve(1, 2, RATE_TS))->toBeNull()
        ->and(rateCounter(1))->toBe(5);
});

it('rejects a charge over the limit with the seconds to the window end and refunds it', function () {
    $this->limiter->reserve(1, 4, RATE_TS);

    expect($this->limiter->reserve(1, 2, RATE_TS))->toBe(50)
        ->and(rateCounter(1))->toBe(4)
        ->and($this->limiter->reserve(1, 1, RATE_TS))->toBeNull();
});

it('reports at least one second at the very end of the window', function () {
    $this->limiter->reserve(1, 4, RATE_TS);
    $this->limiter->reserve(1, 1, RATE_TS);

    expect($this->limiter->reserve(1, 1, intdiv(RATE_TS, 60) * 60 + 59))->toBe(1);
});

it('starts a fresh budget in the next window', function () {
    $this->limiter->reserve(1, 4, RATE_TS);
    $this->limiter->reserve(1, 1, RATE_TS);

    expect($this->limiter->reserve(1, 5, RATE_TS + 50))->toBeNull();
});

it('keeps independent budgets per project', function () {
    $this->limiter->reserve(1, 4, RATE_TS);
    $this->limiter->reserve(1, 1, RATE_TS);

    expect($this->limiter->reserve(2, 5, RATE_TS))->toBeNull();
});

it('never lets the effective limit drop below max_batch_size', function () {
    config(['capture.project_events_per_minute' => 1, 'capture.max_batch_size' => 4]);

    expect($this->limiter->reserve(1, 4, RATE_TS))->toBeNull()
        ->and($this->limiter->reserve(1, 1, RATE_TS))->toBe(50)
        ->and(rateCounter(1))->toBe(4);
});

it('is disabled by zero or a negative limit', function (int $limit) {
    config(['capture.project_events_per_minute' => $limit]);

    expect($this->limiter->reserve(1, 400, RATE_TS))->toBeNull()
        ->and($this->limiter->reserve(1, 400, RATE_TS))->toBeNull()
        ->and(Cache::has('capture-rate:1:'.intdiv(RATE_TS, 60)))->toBeFalse();
})->with([0, -1]);

it('does not reserve an empty request even when the budget is spent', function () {
    $this->limiter->reserve(1, 4, RATE_TS);
    $this->limiter->reserve(1, 1, RATE_TS);

    expect($this->limiter->reserve(1, 0, RATE_TS))->toBeNull()
        ->and(rateCounter(1))->toBe(5);
});

it('keeps the window counter for twice the window length', function () {
    $this->limiter->reserve(1, 1, RATE_TS);

    // The array store keeps the expiry timestamp next to the value.
    $item = (new ReflectionProperty(Cache::getStore(), 'storage'))->getValue(Cache::getStore())['capture-rate:1:'.intdiv(RATE_TS, 60)];
    expect((int) round($item['expiresAt'] - microtime(true)))->toBe(120);
});

it('warns once per window and project when the limit is first exceeded', function () {
    Log::spy();
    $this->limiter->reserve(1, 4, RATE_TS);

    foreach (range(1, 3) as $_) {
        $this->limiter->reserve(1, 2, RATE_TS);
    }
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context === ['project_id' => 1, 'limit' => 5, 'requested' => 2],
    );

    $this->limiter->reserve(2, 4, RATE_TS);
    $this->limiter->reserve(2, 2, RATE_TS);
    $this->limiter->reserve(1, 4, RATE_TS + 60);
    $this->limiter->reserve(1, 2, RATE_TS + 60);
    Log::shouldHaveReceived('warning')->times(3);
});

it('fails open and warns once when the cache is unavailable', function () {
    Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
    config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);
    Cache::forgetDriver();
    Log::spy();

    expect($this->limiter->reserve(1, 4, RATE_TS))->toBeNull()
        ->and($this->limiter->reserve(1, 4, RATE_TS))->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context === ['exception' => RuntimeException::class],
    );
});

it('fails open when the store cannot increment a missing counter', function () {
    // The database store answers false for increment on a missing key.
    Cache::shouldReceive('getStore')->andReturn(new ArrayStore);
    Cache::shouldReceive('add')->andReturn(false);
    Cache::shouldReceive('increment')->andReturn(false);
    Log::spy();

    expect($this->limiter->reserve(1, 4, RATE_TS))->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

it('adds the injected jitter to Retry-After', function () {
    $limiter = new ProjectRateLimiter(fn (): int => 3);
    $limiter->reserve(1, 4, RATE_TS);
    $limiter->reserve(1, 1, RATE_TS);

    expect($limiter->reserve(1, 1, RATE_TS))->toBe(53);
});

it('keeps the default jitter within five seconds', function () {
    $limiter = new ProjectRateLimiter;
    $limiter->reserve(1, 4, RATE_TS);
    $limiter->reserve(1, 1, RATE_TS);

    $seen = [];
    foreach (range(1, 200) as $_) {
        $seen[] = $limiter->reserve(1, 1, RATE_TS);
    }

    expect(min($seen))->toBeGreaterThanOrEqual(50)->and(max($seen))->toBeLessThanOrEqual(55)
        ->and(count(array_unique($seen)))->toBeGreaterThan(1);
});

it('releases reserved events so the budget fits again', function () {
    $this->limiter->reserve(1, 4, RATE_TS);
    $this->limiter->reserve(1, 1, RATE_TS);
    expect($this->limiter->reserve(1, 3, RATE_TS))->toBe(50);

    $this->limiter->release(1, 3, RATE_TS);

    expect(rateCounter(1))->toBe(2)
        ->and($this->limiter->reserve(1, 3, RATE_TS))->toBeNull()
        ->and(rateCounter(1))->toBe(5);
});

it('releases into the window of the given timestamp only', function () {
    $this->limiter->reserve(1, 4, RATE_TS);
    $this->limiter->reserve(1, 3, RATE_TS + 60);

    $this->limiter->release(1, 2, RATE_TS);

    expect(rateCounter(1))->toBe(2)->and(rateCounter(1, RATE_TS + 60))->toBe(3);
});

it('never releases below zero', function () {
    $this->limiter->reserve(1, 2, RATE_TS);

    $this->limiter->release(1, 5, RATE_TS);
    expect(rateCounter(1))->toBe(0);

    // Nothing was ever reserved for this project: no negative counter appears.
    $this->limiter->release(9, 3, RATE_TS);
    expect(rateCounter(9))->toBe(0)
        ->and($this->limiter->reserve(9, 4, RATE_TS))->toBeNull()
        ->and(rateCounter(9))->toBe(4);
});

it('ignores a release of zero or fewer events', function (int $count) {
    $this->limiter->reserve(1, 3, RATE_TS);

    $this->limiter->release(1, $count, RATE_TS);

    expect(rateCounter(1))->toBe(3);
})->with([0, -2]);

it('ignores a release while the limiter is disabled', function () {
    $this->limiter->reserve(1, 3, RATE_TS);
    config(['capture.project_events_per_minute' => 0]);

    $this->limiter->release(1, 3, RATE_TS);

    expect(rateCounter(1))->toBe(3);
});

it('fails open and warns once when a release cannot reach the cache', function () {
    Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
    config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);
    Cache::forgetDriver();
    Log::spy();

    $this->limiter->release(1, 2, RATE_TS);
    $this->limiter->release(1, 2, RATE_TS);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context['exception'] === RuntimeException::class,
    );
});

it('keeps the add/increment path working on a non-redis store', function () {
    // The portable fallback every non-Redis cache store uses.
    config(['capture.project_events_per_minute' => 10, 'capture.max_batch_size' => 5]);
    expect(Cache::getStore())->toBeInstanceOf(ArrayStore::class);

    expect($this->limiter->reserve(424242, 5, RATE_TS))->toBeNull()
        ->and($this->limiter->reserve(424242, 5, RATE_TS))->toBeNull()
        ->and($this->limiter->reserve(424242, 1, RATE_TS))->toBe(50)
        ->and(rateCounter(424242))->toBe(10);

    $this->limiter->release(424242, 4, RATE_TS);
    expect(rateCounter(424242))->toBe(6);
});
