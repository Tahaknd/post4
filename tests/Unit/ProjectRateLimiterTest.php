<?php

use App\Domain\Capture\LimiterOutage;
use App\Domain\Capture\ProjectRateLimiter;
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
    $this->limiter = new ProjectRateLimiter;
});

afterEach(function () {
    (new ReflectionProperty(LimiterOutage::class, 'lastWarningAt'))->setValue(null, 0);
});

it('allows charges within the limit and counts them', function () {
    expect($this->limiter->charge(1, 3, RATE_TS))->toBeNull()
        ->and($this->limiter->charge(1, 2, RATE_TS))->toBeNull()
        ->and(rateCounter(1))->toBe(5);
});

it('rejects a charge over the limit with the seconds to the window end and refunds it', function () {
    $this->limiter->charge(1, 4, RATE_TS);

    expect($this->limiter->charge(1, 2, RATE_TS))->toBe(50)
        ->and(rateCounter(1))->toBe(4)
        ->and($this->limiter->charge(1, 1, RATE_TS))->toBeNull();
});

it('reports at least one second at the very end of the window', function () {
    $this->limiter->charge(1, 4, RATE_TS);
    $this->limiter->charge(1, 1, RATE_TS);

    expect($this->limiter->charge(1, 1, intdiv(RATE_TS, 60) * 60 + 59))->toBe(1);
});

it('starts a fresh budget in the next window', function () {
    $this->limiter->charge(1, 4, RATE_TS);
    $this->limiter->charge(1, 1, RATE_TS);

    expect($this->limiter->charge(1, 5, RATE_TS + 50))->toBeNull();
});

it('keeps independent budgets per project', function () {
    $this->limiter->charge(1, 4, RATE_TS);
    $this->limiter->charge(1, 1, RATE_TS);

    expect($this->limiter->charge(2, 5, RATE_TS))->toBeNull();
});

it('never lets the effective limit drop below max_batch_size', function () {
    config(['capture.project_events_per_minute' => 1, 'capture.max_batch_size' => 4]);

    expect($this->limiter->charge(1, 4, RATE_TS))->toBeNull()
        ->and($this->limiter->charge(1, 1, RATE_TS))->toBe(50)
        ->and(rateCounter(1))->toBe(4);
});

it('is disabled by zero or a negative limit and by an empty charge', function (int $limit) {
    config(['capture.project_events_per_minute' => $limit]);

    expect($this->limiter->charge(1, 400, RATE_TS))->toBeNull()
        ->and($this->limiter->charge(1, 400, RATE_TS))->toBeNull()
        ->and(Cache::has('capture-rate:1:'.intdiv(RATE_TS, 60)))->toBeFalse();
})->with([0, -1]);

it('does not charge an empty request even when the budget is spent', function () {
    $this->limiter->charge(1, 4, RATE_TS);
    $this->limiter->charge(1, 1, RATE_TS);

    expect($this->limiter->charge(1, 0, RATE_TS))->toBeNull()
        ->and(rateCounter(1))->toBe(5);
});

it('gives the window counter the configured ttl', function () {
    config(['capture.project_counter_ttl_seconds' => 33]);
    $this->limiter->charge(1, 1, RATE_TS);

    // The array store keeps the expiry timestamp next to the value.
    $item = (new ReflectionProperty(Cache::getStore(), 'storage'))->getValue(Cache::getStore())['capture-rate:1:'.intdiv(RATE_TS, 60)];
    expect((int) round($item['expiresAt'] - microtime(true)))->toBe(33);
});

it('warns once per window and project when the limit is first exceeded', function () {
    Log::spy();
    $this->limiter->charge(1, 4, RATE_TS);

    foreach (range(1, 3) as $_) {
        $this->limiter->charge(1, 2, RATE_TS);
    }
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context === ['project_id' => 1, 'limit' => 5, 'requested' => 2],
    );

    $this->limiter->charge(2, 4, RATE_TS);
    $this->limiter->charge(2, 2, RATE_TS);
    $this->limiter->charge(1, 4, RATE_TS + 60);
    $this->limiter->charge(1, 2, RATE_TS + 60);
    Log::shouldHaveReceived('warning')->times(3);
});

it('fails open and warns once when the cache is unavailable', function () {
    Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
    config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);
    Cache::forgetDriver();
    Log::spy();

    expect($this->limiter->charge(1, 4, RATE_TS))->toBeNull()
        ->and($this->limiter->charge(1, 4, RATE_TS))->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context === ['exception' => RuntimeException::class],
    );
});

it('fails open when the store cannot increment a missing counter', function () {
    // The database store answers false for increment on a missing key.
    Cache::shouldReceive('add')->andReturn(false);
    Cache::shouldReceive('increment')->andReturn(false);
    Log::spy();

    expect($this->limiter->charge(1, 4, RATE_TS))->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});
