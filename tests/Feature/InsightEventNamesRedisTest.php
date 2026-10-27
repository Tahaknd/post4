<?php

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\Events\EventNamesBusy;
use App\Domain\Insights\Events\EventNamesQuery;
use App\Domain\Insights\Runs\InsightGate;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Tests\Support\RedisIntegration;
use Tests\Support\ScriptedReads;

/*
 * The event-name list's state against a real Redis (database 15, random key prefix; Tests\Support\RedisIntegration):
 * the single slot is atomic and owner-checked across two "workers" and expires by itself, the cached answer always
 * carries a TTL. Opt-in like the other Redis tests:
 *
 *   docker compose exec -T -e REDIS_INTEGRATION_HOST=redis app php artisan test --group=redis
 */

pest()->group('redis');

beforeEach(function () {
    config(['insights.events_cache_ttl_seconds' => 120, 'insights.events_lock_ttl_seconds' => 15, 'insights.events_max_execution_time' => 5]);
    $this->it = RedisIntegration::connect();
    $this->workerA = $this->it->newRepository();
    $this->workerB = $this->it->newRepository();
    $this->real = fn (string $key) => RedisIntegration::DB_PREFIX.$this->it->storePrefix.$key;
    $this->ttl = fn (string $key) => $this->it->raw()->ttl(($this->real)($key));
});

afterEach(function () {
    if (isset($this->it)) {
        $keys = $this->it->raw()->keys(RedisIntegration::DB_PREFIX.$this->it->storePrefix.'*');
        if ($keys !== []) {
            $this->it->raw()->del(...$keys);
        }
        $this->it->cleanup();
    }
});

it('lets one worker hold the single slot, with a TTL, and frees it by release and by expiry', function () {
    $a = new InsightGate($this->workerA);
    $b = new InsightGate($this->workerB);

    $one = $a->acquireEventNames();
    $two = $b->acquireEventNames();

    expect($one)->not->toBeNull()->and($two)->toBeNull()
        ->and(($this->ttl)(InsightGate::EVENT_NAMES_KEY))->toBeGreaterThan(0)->toBeLessThanOrEqual(15);

    $one->release();
    expect(($this->ttl)(InsightGate::EVENT_NAMES_KEY))->toBe(-2); // the key is gone

    $three = $b->acquireEventNames();
    expect($three)->not->toBeNull();

    // a holder that never lets go (killed request): the TTL frees the slot
    $this->it->raw()->expire(($this->real)(InsightGate::EVENT_NAMES_KEY), 1);
    sleep(2);
    expect($a->acquireEventNames())->not->toBeNull();
});

it('releases only for the owner: a stale holder cannot free the slot of the one that took it over', function () {
    $a = new InsightGate($this->workerA);

    $stale = $a->acquireEventNames();
    // the slot expires while the stale holder is still working, another request takes it
    $this->it->raw()->del(($this->real)(InsightGate::EVENT_NAMES_KEY));
    $current = (new InsightGate($this->workerB))->acquireEventNames();
    expect($current)->not->toBeNull();

    $stale->release();

    expect((new InsightGate($this->workerA))->acquireEventNames())->toBeNull()
        ->and(($this->ttl)(InsightGate::EVENT_NAMES_KEY))->toBeGreaterThan(0);
    $current->release();
});

it('caches the answer with the configured TTL, per project and normalised text, and serves it without a second query', function () {
    $reads = new ScriptedReads([[['name' => 'signup', 'total' => 5]], [['name' => 'signup', 'total' => 6]]]);
    $names = new EventNames(new ProjectScopedReader($reads), new EventNamesQuery, new InsightGate($this->workerA), $this->workerA);

    $first = $names->list(77, null);
    $again = $names->list(77, null);
    $filtered = $names->list(77, '  SIGN ');
    $sameFiltered = $names->list(77, 'sign');

    expect($first)->toBe(['events' => [['name' => 'signup', 'count' => 5]], 'truncated' => false])
        ->and($again)->toBe($first)
        ->and($filtered['events'][0]['count'])->toBe(6)->and($sameFiltered)->toBe($filtered)
        ->and($reads->calls)->toHaveCount(2)
        ->and(($this->ttl)(EventNames::cacheKey(77, null)))->toBeGreaterThan(0)->toBeLessThanOrEqual(120)
        ->and(($this->ttl)(EventNames::cacheKey(77, 'sign')))->toBeGreaterThan(0)->toBeLessThanOrEqual(120)
        ->and(($this->ttl)(EventNames::cacheKey(78, null)))->toBe(-2);
});

it('leaves no slot behind after a query that fails and no cache entry either', function () {
    $reads = new ScriptedReads([new RuntimeException('boom')]);
    $names = new EventNames(new ProjectScopedReader($reads), new EventNamesQuery, new InsightGate($this->workerA), $this->workerA);

    expect(fn () => $names->list(79, 'x'))->toThrow(RuntimeException::class);

    expect(($this->ttl)(InsightGate::EVENT_NAMES_KEY))->toBe(-2)->and(($this->ttl)(EventNames::cacheKey(79, 'x')))->toBe(-2);
});

it('throws busy while another worker holds the slot and the cache has nothing', function () {
    $held = (new InsightGate($this->workerB))->acquireEventNames();
    $names = new EventNames(new ProjectScopedReader(new ScriptedReads([[]])), new EventNamesQuery, new InsightGate($this->workerA), $this->workerA);

    expect(fn () => $names->list(80, null))->toThrow(EventNamesBusy::class);
    $held->release();
    expect($names->list(80, null))->toBe(['events' => [], 'truncated' => false]);
});
