<?php

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Jobs\RunTrendsInsight;
use App\Models\Project;
use Tests\Support\FakeQueueJob;
use Tests\Support\RedisIntegration;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

/*
 * The insight run state against a real Redis (database 15, random key prefix; see Tests\Support\RedisIntegration):
 * every key carries a TTL, the locks are atomic and owner-checked across two "workers" (two repositories on the
 * same Redis), and a whole job run leaves no key without an expiry. Opt-in like the limiter tests:
 *
 *   docker compose exec -T -e REDIS_INTEGRATION_HOST=redis app php artisan test --group=redis
 */

pest()->group('redis');

beforeEach(function () {
    config(['insights.cache_ttl_seconds' => 45, 'insights.cache_warning_interval_seconds' => 60, 'insights.max_runs_per_project' => 3, 'insights.max_running_per_project' => 2]);
    $this->it = RedisIntegration::connect();
    $this->workerA = $this->it->newRepository();
    $this->workerB = $this->it->newRepository();
    $this->real = fn (string $key) => RedisIntegration::DB_PREFIX.$this->it->storePrefix.$key;
    $this->ttl = fn (string $key) => $this->it->raw()->ttl(($this->real)($key));
    $this->project = Project::factory()->create();
});

afterEach(function () {
    if (isset($this->it)) {
        // Every key this test created lives under its random store prefix: remove exactly those.
        $keys = $this->it->raw()->keys(RedisIntegration::DB_PREFIX.$this->it->storePrefix.'*');
        if ($keys !== []) {
            $this->it->raw()->del(...$keys);
        }
        $this->it->cleanup();
    }
});

it('gives the run record a TTL at every stage and loses nothing in serialization', function () {
    $store = new InsightRunStore($this->workerA);
    $id = InsightRunStore::newId();
    $result = T::runner(new ScriptedReads([T::dayRows()]), $this->workerA)->trends($this->project->id, T::definition());

    $store->create($id, $this->project->id, 7, 'hash', 0);
    $queued = ($this->ttl)(InsightRunStore::key($id));
    $store->markRunning($id);
    $running = ($this->ttl)(InsightRunStore::key($id));
    $store->markDone($id, $result);
    $done = ($this->ttl)(InsightRunStore::key($id));

    expect($queued)->toBeGreaterThan(0)->toBeLessThanOrEqual(900)
        ->and($running)->toBeGreaterThan(0)->toBeLessThanOrEqual(900)
        ->and($done)->toBeGreaterThan(0)->toBeLessThanOrEqual(300)
        ->and((new InsightRunStore($this->workerB))->find($id)['result'])->toBe($result->toArray());

    $store->markFailed($id, ClickHouseErrorKind::Timeout);
    expect(($this->ttl)(InsightRunStore::key($id)))->toBeGreaterThan(0)->toBeLessThanOrEqual(300)
        ->and((new InsightRunStore($this->workerB))->find($id)['error'])->toBe('timeout');

    $store->forget($id);
    expect($this->it->raw()->exists(($this->real)(InsightRunStore::key($id))))->toBe(0);
});

it('does not resurrect a record that expired while the job ran', function () {
    $store = new InsightRunStore($this->workerA);
    $id = InsightRunStore::newId();

    $store->markDone($id, T::runner(new ScriptedReads([T::dayRows()]), $this->workerA)->trends($this->project->id, T::definition()));

    expect($this->it->raw()->exists(($this->real)(InsightRunStore::key($id))))->toBe(0);
});

it('holds the dispatch slots atomically across workers, with a TTL, and only the owner releases one', function () {
    $a = new InsightGate($this->workerA);
    $b = new InsightGate($this->workerB);

    $slots = [$a->acquireDispatchSlot($this->project->id, 'run-1'), $b->acquireDispatchSlot($this->project->id, 'run-2'), $a->acquireDispatchSlot($this->project->id, 'run-3')];
    $full = $b->acquireDispatchSlot($this->project->id, 'run-4');

    expect($slots)->toBe([0, 1, 2])->and($full)->toBeNull()
        ->and(($this->ttl)(InsightGate::dispatchKey($this->project->id, 0)))->toBeGreaterThan(0)->toBeLessThanOrEqual(900);

    $b->releaseDispatchSlot($this->project->id, 'not-the-owner', 1); // another worker, wrong owner: no effect
    expect($a->acquireDispatchSlot($this->project->id, 'run-5'))->toBeNull();

    $b->releaseDispatchSlot($this->project->id, 'run-1', 0); // a different worker releases with the right owner
    expect($a->acquireDispatchSlot($this->project->id, 'run-5'))->toBe(0);
});

it('caps the running queries per project across workers and expires the lock by itself', function () {
    $a = new InsightGate($this->workerA);
    $b = new InsightGate($this->workerB);

    $one = $a->acquireRunning($this->project->id, 'r1');
    $two = $b->acquireRunning($this->project->id, 'r2');
    $three = $a->acquireRunning($this->project->id, 'r3');

    expect($one)->not->toBeNull()->and($two)->not->toBeNull()->and($three)->toBeNull()
        ->and(($this->ttl)("insights:running:{$this->project->id}:0"))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($b->acquireRunning(999, 'other-project'))->not->toBeNull();

    $one->release();
    expect($b->acquireRunning($this->project->id, 'r3'))->not->toBeNull();
});

it('lets only one worker hold the single-flight lock of a definition, with a TTL', function () {
    $a = new InsightGate($this->workerA);
    $b = new InsightGate($this->workerB);

    $holder = $a->acquireFlight($this->project->id, 'hash-1', 'r1');

    expect($holder)->not->toBeNull()
        ->and($b->acquireFlight($this->project->id, 'hash-1', 'r2'))->toBeNull()
        ->and($b->acquireFlight($this->project->id, 'hash-2', 'r2'))->not->toBeNull()
        ->and(($this->ttl)("insights:flight:{$this->project->id}:hash-1"))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    $holder->release();
    expect($b->acquireFlight($this->project->id, 'hash-1', 'r2'))->not->toBeNull();
});

it('leaves no key without an expiry after a whole job run, and the waiter of a second worker copies the result', function () {
    $query = T::definition();
    $storeA = new InsightRunStore($this->workerA);
    $gateA = new InsightGate($this->workerA);
    $client = new ScriptedReads([T::dayRows()]);

    $first = InsightRunStore::newId();
    $slot = $gateA->acquireDispatchSlot($this->project->id, $first);
    $storeA->create($first, $this->project->id, 7, $query->hash(), $slot);
    $job = new RunTrendsInsight($this->project->id, $first, $query->canonical());
    $job->setJob(new FakeQueueJob);
    $job->handle(T::runner($client, $this->workerA), $storeA, $gateA);

    // a second run of the same definition on ANOTHER worker: served from the shared result cache, no query
    $storeB = new InsightRunStore($this->workerB);
    $gateB = new InsightGate($this->workerB);
    $second = InsightRunStore::newId();
    $storeB->create($second, $this->project->id, 8, $query->hash(), $gateB->acquireDispatchSlot($this->project->id, $second));
    $secondClient = new ScriptedReads([]);
    $other = new RunTrendsInsight($this->project->id, $second, $query->canonical());
    $other->setJob(new FakeQueueJob);
    $other->handle(T::runner($secondClient, $this->workerB), $storeB, $gateB);

    expect($storeB->find($second)['status'])->toBe('done')
        ->and($storeB->find($second)['result'])->toBe($storeA->find($first)['result'])
        ->and(count($client->calls))->toBe(1)->and($secondClient->calls)->toBe([]);

    $keys = $this->it->raw()->keys(RedisIntegration::DB_PREFIX.$this->it->storePrefix.'*');
    expect($keys)->not->toBeEmpty();
    foreach ($keys as $key) {
        $ttl = $this->it->raw()->ttl($key);
        expect($ttl)->toBeGreaterThan(0, "key {$key} has no expiry (ttl {$ttl})");
    }
    // the finished runs gave their dispatch slots back
    expect($gateA->acquireDispatchSlot($this->project->id, 'next'))->toBe(0);
});

it('answers the shared result cache from both workers under the insight key with the configured TTL', function () {
    $query = T::definition();
    T::runner(new ScriptedReads([T::dayRows()]), $this->workerA)->trends($this->project->id, $query);

    $ttl = ($this->ttl)(InsightRunner::cacheKey($this->project->id, $query));

    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(45)
        ->and(T::runner(new ScriptedReads([]), $this->workerB)->cached($this->project->id, $query)?->toArray())->not->toBeNull();
});
