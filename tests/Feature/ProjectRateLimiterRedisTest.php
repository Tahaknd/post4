<?php

use App\Domain\Capture\ProjectRateLimiter;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;

/*
 * Opt-in: runs the limiter against a real Redis, because the array cache cannot try TTL, INCRBY, DECRBY
 * or EVAL. Enable with REDIS_INTEGRATION_HOST (port 6379), e.g.
 *   docker compose exec -e REDIS_INTEGRATION_HOST=redis app php artisan test --filter=ProjectRateLimiterRedisTest
 *
 * The phpunit environment pins REDIS_HOST to a dead address on purpose, so this test never goes through
 * the app's redis config: it opens its OWN connection to database 15 (never 0 = queues, 1 = app cache),
 * under a random key prefix, and deletes only the keys it created.
 */

const REDIS_IT_DATABASE = 15;
const REDIS_IT_TS = 1_792_670_410; // 12:00:10 UTC: 50 s before the window ends

/** @var list<string> keys (prefix included) created by the running test */
$GLOBALS['redis_it_keys'] = [];

/** @return array{0: RedisManager, 1: string} */
function redisIntegrationManager(): array
{
    $host = getenv('REDIS_INTEGRATION_HOST');
    if (! is_string($host) || $host === '') {
        test()->markTestSkipped('Set REDIS_INTEGRATION_HOST to run the Redis integration tests.');
    }

    $manager = new RedisManager(app(), 'phpredis', ['default' => [
        'host' => $host,
        'port' => 6379,
        'database' => REDIS_IT_DATABASE,
    ]]);
    $prefix = 'ratelimit-it-'.bin2hex(random_bytes(6)).':';

    // Hard stop: the connection must really be database 15 before anything is written.
    expect($manager->connection('default')->client()->getDbNum())->toBe(REDIS_IT_DATABASE);

    return [$manager, $prefix];
}

function redisRepository(RedisManager $manager, string $prefix): Repository
{
    return new Repository(new RedisStore($manager, $prefix, 'default'));
}

function redisRawKey(string $prefix, int $projectId): string
{
    return $prefix."capture-rate:{$projectId}:".intdiv(REDIS_IT_TS, 60);
}

/** Registers the keys of a project for cleanup and returns its project id. */
function redisProject(string $prefix): int
{
    $projectId = random_int(1_000_000_000, 2_000_000_000);
    $GLOBALS['redis_it_keys'][] = redisRawKey($prefix, $projectId);
    $GLOBALS['redis_it_keys'][] = $prefix."capture-rate-warned:{$projectId}:".intdiv(REDIS_IT_TS, 60);

    return $projectId;
}

function redisConnection(RedisManager $manager): Connection
{
    return $manager->connection('default');
}

beforeEach(function () {
    $GLOBALS['redis_it_keys'] = [];
    config(['capture.project_events_per_minute' => 10, 'capture.max_batch_size' => 5]);
    $this->limiter = new ProjectRateLimiter(fn (): int => 0);
});

afterEach(function () {
    $host = getenv('REDIS_INTEGRATION_HOST');
    if (is_string($host) && $host !== '' && $GLOBALS['redis_it_keys'] !== []) {
        $manager = new RedisManager(app(), 'phpredis', ['default' => ['host' => $host, 'port' => 6379, 'database' => REDIS_IT_DATABASE]]);
        $manager->connection('default')->del(...$GLOBALS['redis_it_keys']);
        $manager->connection('default')->disconnect();
    }
    $GLOBALS['redis_it_keys'] = [];
});

it('reserves atomically through EVAL and gives the new counter a ttl', function () {
    [$manager, $prefix] = redisIntegrationManager();
    Cache::swap(redisRepository($manager, $prefix));
    expect(Cache::getStore())->toBeInstanceOf(RedisStore::class);
    $id = redisProject($prefix);
    $raw = redisRawKey($prefix, $id);
    $redis = redisConnection($manager);

    expect($this->limiter->reserve($id, 3, REDIS_IT_TS))->toBeNull();

    $ttl = $redis->ttl($raw);
    expect((int) $redis->get($raw))->toBe(3)
        ->and($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(120);

    // A later reservation keeps counting and does not push the expiry out.
    $redis->expire($raw, 50);
    expect($this->limiter->reserve($id, 2, REDIS_IT_TS))->toBeNull()
        ->and((int) $redis->get($raw))->toBe(5)
        ->and($redis->ttl($raw))->toBeLessThanOrEqual(50);
});

it('refunds a reservation that exceeds the limit and reports it as rejected', function () {
    [$manager, $prefix] = redisIntegrationManager();
    Cache::swap(redisRepository($manager, $prefix));
    $id = redisProject($prefix);
    $raw = redisRawKey($prefix, $id);
    $redis = redisConnection($manager);

    $this->limiter->reserve($id, 5, REDIS_IT_TS);
    $this->limiter->reserve($id, 4, REDIS_IT_TS);
    expect((int) $redis->get($raw))->toBe(9);

    expect($this->limiter->reserve($id, 2, REDIS_IT_TS))->toBe(50)
        ->and((int) $redis->get($raw))->toBe(9)
        ->and($this->limiter->reserve($id, 1, REDIS_IT_TS))->toBeNull()
        ->and((int) $redis->get($raw))->toBe(10)
        ->and($this->limiter->reserve($id, 1, REDIS_IT_TS))->toBe(50)
        ->and((int) $redis->get($raw))->toBe(10)
        ->and($redis->ttl($raw))->toBeGreaterThan(0);
});

it('releases reserved events without going below zero and keeps the ttl', function () {
    [$manager, $prefix] = redisIntegrationManager();
    Cache::swap(redisRepository($manager, $prefix));
    $id = redisProject($prefix);
    $raw = redisRawKey($prefix, $id);
    $redis = redisConnection($manager);

    $this->limiter->reserve($id, 5, REDIS_IT_TS);
    $redis->expire($raw, 77);

    $this->limiter->release($id, 2, REDIS_IT_TS);
    expect((int) $redis->get($raw))->toBe(3)
        ->and($redis->ttl($raw))->toBeGreaterThan(0)->toBeLessThanOrEqual(77);

    // Over-releasing leaves no negative counter behind, and the budget is whole again.
    $this->limiter->release($id, 50, REDIS_IT_TS);
    expect((int) $redis->get($raw))->toBe(0)
        ->and($this->limiter->reserve($id, 5, REDIS_IT_TS))->toBeNull()
        ->and($this->limiter->reserve($id, 5, REDIS_IT_TS))->toBeNull()
        ->and($this->limiter->reserve($id, 1, REDIS_IT_TS))->toBe(50);

    // A release for a window that has no counter (expired) creates no key at all.
    $later = $prefix."capture-rate:{$id}:".intdiv(REDIS_IT_TS + 600, 60);
    $GLOBALS['redis_it_keys'][] = $later;
    $this->limiter->release($id, 3, REDIS_IT_TS + 600);
    expect($redis->exists($later))->toBe(0);
});

it('shares one counter between repository instances', function () {
    [$manager, $prefix] = redisIntegrationManager();
    $id = redisProject($prefix);

    Cache::swap(redisRepository($manager, $prefix));
    $this->limiter->reserve($id, 4, REDIS_IT_TS);

    Cache::swap(redisRepository($manager, $prefix));
    expect($this->limiter->reserve($id, 4, REDIS_IT_TS))->toBeNull()
        ->and($this->limiter->reserve($id, 4, REDIS_IT_TS))->toBe(50)
        ->and((int) redisConnection($manager)->get(redisRawKey($prefix, $id)))->toBe(8);
});

it('never lets the stored counter exceed the limit under repeated mixed reservations', function () {
    [$manager, $prefix] = redisIntegrationManager();
    $repositories = [redisRepository($manager, $prefix), redisRepository($manager, $prefix)];
    $id = redisProject($prefix);
    $redis = redisConnection($manager);

    $accepted = 0;
    foreach (range(1, 60) as $i) {
        Cache::swap($repositories[$i % 2]);
        $count = ($i % 5) + 1;
        if ($this->limiter->reserve($id, $count, REDIS_IT_TS) === null) {
            $accepted += $count;
        }
        expect((int) $redis->get(redisRawKey($prefix, $id)))->toBeLessThanOrEqual(10);
    }

    // The counter is exactly the accepted events: every rejected reservation was refunded in full.
    expect($accepted)->toBeLessThanOrEqual(10)
        ->and((int) $redis->get(redisRawKey($prefix, $id)))->toBe($accepted);
});
