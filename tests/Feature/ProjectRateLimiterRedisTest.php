<?php

use App\Domain\Capture\ProjectRateLimiter;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Tests\Support\RedisIntegration;

/*
 * Runs the limiter against a real Redis, because the array cache cannot try TTL, INCRBY, DECRBY, EVAL or a
 * race between processes. Opt-in: the whole file is in the group "redis", which phpunit.xml excludes from
 * the default run, so `php artisan test` neither runs nor reports these tests. Run them with
 *
 *   docker compose exec -T -e REDIS_INTEGRATION_HOST=redis app php artisan test --group=redis
 *
 * (a command line --group replaces the excluded group from phpunit.xml). Without REDIS_INTEGRATION_HOST
 * (port 6379) they fail with a message instead of skipping. Everything happens in database 15 under a random
 * key prefix, see Tests\Support\RedisIntegration.
 */

pest()->group('redis');

beforeEach(function () {
    config(['capture.project_events_per_minute' => 10, 'capture.max_batch_size' => 5]);
    $this->limiter = new ProjectRateLimiter(fn (): int => 0);
    $this->it = RedisIntegration::connect();
});

afterEach(function () {
    if (isset($this->it)) {
        $this->it->cleanup();
    }
    LimiterOutage::reset();
});

it('reserves atomically through EVAL and gives the new counter a ttl', function () {
    $this->it->useAsCache();
    expect(Cache::getStore())->toBeInstanceOf(RedisStore::class);
    $id = $this->it->project();
    $raw = $this->it->counterKey($id);

    expect($this->limiter->reserve($id, 3, RedisIntegration::TS))->toBeNull();

    $ttl = $this->it->raw()->ttl($raw);
    expect($this->it->counter($id))->toBe(3)
        ->and($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(120);

    // A later reservation keeps counting and does not push the expiry out.
    $this->it->raw()->expire($raw, 50);
    expect($this->limiter->reserve($id, 2, RedisIntegration::TS))->toBeNull()
        ->and($this->it->counter($id))->toBe(5)
        ->and($this->it->raw()->ttl($raw))->toBeLessThanOrEqual(50);
});

it('writes the key under the connection prefix and the cache prefix exactly once', function () {
    $this->it->useAsCache();
    $id = $this->it->project();

    $this->limiter->reserve($id, 2, RedisIntegration::TS);

    // The unprefixed connection sees the real name: no missing prefix and no double prefix.
    expect($this->it->raw()->keys("*capture-rate:{$id}:*"))->toBe([$this->it->counterKey($id)])
        ->and($this->it->counterKey($id))->toStartWith('it-db-ratelimit-it-')
        ->and(substr_count($this->it->counterKey($id), RedisIntegration::DB_PREFIX))->toBe(1);
});

it('refunds a reservation that exceeds the limit and reports it as rejected', function () {
    $this->it->useAsCache();
    $id = $this->it->project();

    $this->limiter->reserve($id, 5, RedisIntegration::TS);
    $this->limiter->reserve($id, 4, RedisIntegration::TS);
    expect($this->it->counter($id))->toBe(9);

    expect($this->limiter->reserve($id, 2, RedisIntegration::TS))->toBe(50)
        ->and($this->it->counter($id))->toBe(9)
        ->and($this->limiter->reserve($id, 1, RedisIntegration::TS))->toBeNull()
        ->and($this->it->counter($id))->toBe(10)
        ->and($this->limiter->reserve($id, 1, RedisIntegration::TS))->toBe(50)
        ->and($this->it->counter($id))->toBe(10)
        ->and($this->it->raw()->ttl($this->it->counterKey($id)))->toBeGreaterThan(0);
});

it('gives a counter without an expiry its ttl on the next reservation', function () {
    $this->it->useAsCache();
    $id = $this->it->project();
    $raw = $this->it->counterKey($id);

    $this->limiter->reserve($id, 3, RedisIntegration::TS);
    $this->it->raw()->persist($raw);
    expect($this->it->raw()->ttl($raw))->toBe(-1);

    // The Lua TTL < 0 branch heals a key that lost its expiry (e.g. after a crash between INCR and EXPIRE).
    $this->limiter->reserve($id, 1, RedisIntegration::TS);

    expect($this->it->counter($id))->toBe(4)
        ->and($this->it->raw()->ttl($raw))->toBeGreaterThan(0)->toBeLessThanOrEqual(120);
});

it('releases reserved events without going below zero and keeps the ttl', function () {
    $this->it->useAsCache();
    $id = $this->it->project();
    $raw = $this->it->counterKey($id);

    $this->limiter->reserve($id, 5, RedisIntegration::TS);
    $this->it->raw()->expire($raw, 77);

    $this->limiter->release($id, 2, RedisIntegration::TS);
    expect($this->it->counter($id))->toBe(3)
        ->and($this->it->raw()->ttl($raw))->toBeGreaterThan(0)->toBeLessThanOrEqual(77);

    // Over-releasing leaves no negative counter behind, and the budget is whole again.
    $this->limiter->release($id, 50, RedisIntegration::TS);
    expect($this->it->counter($id))->toBe(0)
        ->and($this->limiter->reserve($id, 5, RedisIntegration::TS))->toBeNull()
        ->and($this->limiter->reserve($id, 5, RedisIntegration::TS))->toBeNull()
        ->and($this->limiter->reserve($id, 1, RedisIntegration::TS))->toBe(50);

    // A release for a window that has no counter (expired) creates no key at all.
    $later = RedisIntegration::TS + 600;
    $laterKey = $this->it->counterKey($id, $later);
    $this->it->track($laterKey);
    $this->limiter->release($id, 3, $later);
    expect($this->it->raw()->exists($laterKey))->toBe(0);
});

it('shares one counter between repository instances', function () {
    $id = $this->it->project();

    Cache::swap($this->it->newRepository());
    $this->limiter->reserve($id, 4, RedisIntegration::TS);

    Cache::swap($this->it->newRepository());
    expect($this->limiter->reserve($id, 4, RedisIntegration::TS))->toBeNull()
        ->and($this->limiter->reserve($id, 4, RedisIntegration::TS))->toBe(50)
        ->and($this->it->counter($id))->toBe(8);
});

it('never lets the stored counter exceed the limit under repeated mixed reservations', function () {
    $repositories = [$this->it->newRepository(), $this->it->newRepository()];
    $id = $this->it->project();

    $accepted = 0;
    foreach (range(1, 60) as $i) {
        Cache::swap($repositories[$i % 2]);
        $count = ($i % 5) + 1;
        if ($this->limiter->reserve($id, $count, RedisIntegration::TS) === null) {
            $accepted += $count;
        }
        expect($this->it->counter($id))->toBeLessThanOrEqual(10);
    }

    // The counter is exactly the accepted events: every rejected reservation was refunded in full.
    expect($accepted)->toBeLessThanOrEqual(10)
        ->and($this->it->counter($id))->toBe($accepted);
});

it('keeps the counter exact and under the limit with eight processes reserving and releasing at once', function () {
    $id = $this->it->project();
    $limit = 200;
    $maxBatch = 5;
    $workers = 8;
    $rounds = 150;
    $script = base_path('tests/Support/redis_race_worker.php');
    $startAt = microtime(true) + 1.0;

    $processes = [];
    foreach (range(1, $workers) as $n) {
        $command = [PHP_BINARY, $script, RedisIntegration::host(), $this->it->storePrefix, (string) $id,
            (string) RedisIntegration::TS, (string) $limit, (string) $maxBatch, (string) $rounds, (string) (1000 + $n), (string) $startAt];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect($process)->not->toBeFalse();
        $processes[$n] = ['process' => $process, 'pipes' => $pipes];
    }

    // Watch the stored counter while they run: a reservation is atomic, so it is never seen above the limit.
    $highest = 0;
    $deadline = microtime(true) + 30;
    do {
        $highest = max($highest, $this->it->counter($id));
        $running = array_filter($processes, fn (array $p) => proc_get_status($p['process'])['running']);
        usleep(2000);
    } while ($running !== [] && microtime(true) < $deadline);

    $kept = 0;
    $rejected = 0;
    foreach ($processes as $n => $p) {
        $stdout = stream_get_contents($p['pipes'][1]);
        $stderr = stream_get_contents($p['pipes'][2]);
        $exit = proc_close($p['process']);
        expect($exit)->toBe(0, "worker {$n} failed: {$stderr}");
        $result = json_decode(trim($stdout), true);
        expect($result)->toBeArray("worker {$n} printed: {$stdout}{$stderr}");
        $kept += $result['kept'];
        $rejected += $result['rejected'];
    }

    $final = $this->it->counter($id);
    expect($rejected)->toBeGreaterThan(0)                // the limit really was contended
        ->and($kept)->toBeGreaterThan(0)
        ->and($highest)->toBeLessThanOrEqual($limit)
        ->and($final)->toBeLessThanOrEqual($limit)
        ->and($final)->toBe($kept);                       // nothing lost or double counted
});
