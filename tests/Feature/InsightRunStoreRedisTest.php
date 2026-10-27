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
    config(['insights.cache_ttl_seconds' => 45, 'insights.cache_warning_interval_seconds' => 60, 'insights.max_active_runs_per_project' => 3, 'insights.max_active_runs_per_user' => 2, 'insights.max_concurrent_queries_per_project' => 2]);
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

    $store->create($id, InsightRunStore::TYPE_TRENDS, $this->project->id, 7, 'hash', 0, 0);
    $queued = ($this->ttl)(InsightRunStore::key($id));
    $store->markRunning($id);
    $running = ($this->ttl)(InsightRunStore::key($id));
    $store->markDone($id, $result->toArray());
    $done = ($this->ttl)(InsightRunStore::key($id));

    expect($queued)->toBeGreaterThan(0)->toBeLessThanOrEqual(900)
        ->and($running)->toBeGreaterThan(0)->toBeLessThanOrEqual(900)
        ->and($done)->toBeGreaterThan(0)->toBeLessThanOrEqual(300)
        ->and((new InsightRunStore($this->workerB))->find($id)['result'])->toBe($result->toArray());

    // a finished run is terminal: a later failure does not rewrite it
    expect($store->markFailed($id, ClickHouseErrorKind::Timeout))->toBeFalse();

    $failing = InsightRunStore::newId();
    $store->create($failing, InsightRunStore::TYPE_TRENDS, $this->project->id, 7, 'hash', 0, 0);
    $store->markFailed($failing, ClickHouseErrorKind::Timeout);
    expect(($this->ttl)(InsightRunStore::key($failing)))->toBeGreaterThan(0)->toBeLessThanOrEqual(300)
        ->and((new InsightRunStore($this->workerB))->find($failing)['error'])->toBe('timeout');

    $store->forget($id);
    expect($this->it->raw()->exists(($this->real)(InsightRunStore::key($id))))->toBe(0);
});

it('does not resurrect a record that expired while the job ran', function () {
    $store = new InsightRunStore($this->workerA);
    $id = InsightRunStore::newId();

    expect($store->markDone($id, T::runner(new ScriptedReads([T::dayRows()]), $this->workerA)->trends($this->project->id, T::definition())->toArray()))->toBeFalse();

    expect($this->it->raw()->exists(($this->real)(InsightRunStore::key($id))))->toBe(0);
});

it('holds the dispatch slots atomically across workers, with a TTL, and only the owner releases one', function () {
    $a = new InsightGate($this->workerA);
    $b = new InsightGate($this->workerB);

    $slots = [$a->acquireProjectSlot($this->project->id, 'run-1'), $b->acquireProjectSlot($this->project->id, 'run-2'), $a->acquireProjectSlot($this->project->id, 'run-3')];
    $full = $b->acquireProjectSlot($this->project->id, 'run-4');

    expect($slots)->toBe([0, 1, 2])->and($full)->toBeNull()
        ->and(($this->ttl)(InsightGate::projectSlotKey($this->project->id, 0)))->toBeGreaterThan(0)->toBeLessThanOrEqual(900);

    $b->releaseSlots($this->project->id, 1, 'not-the-owner', 1, null); // another worker, wrong owner: no effect
    expect($a->acquireProjectSlot($this->project->id, 'run-5'))->toBeNull();

    $b->releaseSlots($this->project->id, 1, 'run-1', 0, null); // a different worker releases with the right owner
    expect($a->acquireProjectSlot($this->project->id, 'run-5'))->toBe(0);
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

    $holder = $a->acquireFlight(InsightRunStore::TYPE_TRENDS, $this->project->id, 'hash-1', 'r1');

    expect($holder)->not->toBeNull()
        ->and($b->acquireFlight(InsightRunStore::TYPE_TRENDS, $this->project->id, 'hash-1', 'r2'))->toBeNull()
        ->and($b->acquireFlight(InsightRunStore::TYPE_TRENDS, $this->project->id, 'hash-2', 'r2'))->not->toBeNull()
        ->and(($this->ttl)("insights:flight:trends:{$this->project->id}:hash-1"))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    $holder->release();
    expect($b->acquireFlight(InsightRunStore::TYPE_TRENDS, $this->project->id, 'hash-1', 'r2'))->not->toBeNull();
});

it('leaves no key without an expiry after a whole job run, and the waiter of a second worker copies the result', function () {
    $query = T::definition();
    $storeA = new InsightRunStore($this->workerA);
    $gateA = new InsightGate($this->workerA);
    $client = new ScriptedReads([T::dayRows()]);

    $first = InsightRunStore::newId();
    $slot = $gateA->acquireProjectSlot($this->project->id, $first);
    $userSlot = $gateA->acquireUserSlot(7, $first);
    $gateA->claimInflight($this->project->id, 7, $query->hash(), $first);
    $storeA->create($first, InsightRunStore::TYPE_TRENDS, $this->project->id, 7, $query->hash(), $slot, $userSlot);
    $job = new RunTrendsInsight($this->project->id, $first, $query->canonical(), 7, $slot, $userSlot);
    $job->setJob(new FakeQueueJob);
    $job->handle(T::runner($client, $this->workerA), $storeA, $gateA);

    // a second run of the same definition on ANOTHER worker: served from the shared result cache, no query
    $storeB = new InsightRunStore($this->workerB);
    $gateB = new InsightGate($this->workerB);
    $second = InsightRunStore::newId();
    $slotB = $gateB->acquireProjectSlot($this->project->id, $second);
    $userSlotB = $gateB->acquireUserSlot(8, $second);
    $storeB->create($second, InsightRunStore::TYPE_TRENDS, $this->project->id, 8, $query->hash(), $slotB, $userSlotB);
    $secondClient = new ScriptedReads([]);
    $other = new RunTrendsInsight($this->project->id, $second, $query->canonical(), 8, $slotB, $userSlotB);
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
    // the finished runs gave their dispatch slots and the inflight key back
    expect($gateA->acquireProjectSlot($this->project->id, 'next'))->toBe(0)
        ->and($gateA->acquireUserSlot(7, 'next'))->toBe(0)
        ->and($gateA->inflightRunId($this->project->id, 7, $query->hash()))->toBeNull();
});

it('answers the shared result cache from both workers under the insight key with the configured TTL', function () {
    $query = T::definition();
    T::runner(new ScriptedReads([T::dayRows()]), $this->workerA)->trends($this->project->id, $query);

    $ttl = ($this->ttl)(InsightRunner::cacheKey($this->project->id, $query));

    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(45)
        ->and(T::runner(new ScriptedReads([]), $this->workerB)->cached($this->project->id, $query)?->toArray())->not->toBeNull();
});

it('keeps every key of the new gates under a TTL: user slots, inflight, flight failure, run record refreshed on running', function () {
    $gate = new InsightGate($this->workerA);
    $store = new InsightRunStore($this->workerA);
    $id = InsightRunStore::newId();

    $slot = $gate->acquireUserSlot(7, $id);
    $claimed = $gate->claimInflight($this->project->id, 7, 'h1', $id);
    $gate->rememberFlightFailure(InsightRunStore::TYPE_TRENDS, $this->project->id, 'h1', ClickHouseErrorKind::Timeout);
    $store->create($id, InsightRunStore::TYPE_TRENDS, $this->project->id, 7, 'h1', 0, $slot);
    $store->markRunning($id);

    expect($slot)->toBe(0)->and($claimed)->toBeTrue()
        ->and(($this->ttl)(InsightGate::userSlotKey(7, 0)))->toBeGreaterThan(0)->toBeLessThanOrEqual(900)
        ->and(($this->ttl)(InsightGate::inflightKey($this->project->id, 7, 'h1')))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and(($this->ttl)(InsightGate::flightFailureKey(InsightRunStore::TYPE_TRENDS, $this->project->id, 'h1')))->toBeGreaterThan(0)->toBeLessThanOrEqual(12)
        ->and(($this->ttl)(InsightRunStore::key($id)))->toBeGreaterThan(0)->toBeLessThanOrEqual(900)
        ->and((new InsightGate($this->workerB))->flightFailure(InsightRunStore::TYPE_TRENDS, $this->project->id, 'h1'))->toBe(ClickHouseErrorKind::Timeout)
        // atomic claim across workers: the second worker loses and sees the holder
        ->and((new InsightGate($this->workerB))->claimInflight($this->project->id, 7, 'h1', 'b'.str_repeat('0', 31)))->toBeFalse()
        ->and((new InsightGate($this->workerB))->inflightRunId($this->project->id, 7, 'h1'))->toBe($id);

    // after the lock-guarded transitions no run lock is left behind
    expect($this->it->raw()->keys(RedisIntegration::DB_PREFIX.$this->it->storePrefix.'insights:runlock:*'))->toBe([]);
});

it('resolves transitions on a real Redis: a cancel beats a later answer and an answer beats a later cancel', function () {
    $a = new InsightRunStore($this->workerA);
    $b = new InsightRunStore($this->workerB);
    $one = InsightRunStore::newId();
    $two = InsightRunStore::newId();
    foreach ([$one, $two] as $id) {
        $a->create($id, InsightRunStore::TYPE_TRENDS, $this->project->id, 7, 'h', null, null);
        $a->markRunning($id);
    }

    expect($a->cancel($one))->toBeTrue()->and($b->markDone($one, ['x' => 1]))->toBeFalse()
        ->and($b->markDone($two, ['x' => 1]))->toBeTrue()->and($a->cancel($two))->toBeFalse()
        ->and($a->find($one)['status'])->toBe('cancelled')->and($a->find($two)['status'])->toBe('done');
});

/** Runs the child workers of tests/Support/insight_race_worker.php and returns their decoded JSON lines. */
function insightRace(object $t, array $argSets, float $startDelay = 1.0): array
{
    $script = base_path('tests/Support/insight_race_worker.php');
    $startAt = microtime(true) + $startDelay;
    $processes = [];
    foreach ($argSets as $n => $args) {
        $mode = array_shift($args);
        $command = match ($mode) {
            'slots' => [PHP_BINARY, $script, 'slots', RedisIntegration::host(), $t->it->storePrefix, ...$args, (string) $startAt],
            default => [PHP_BINARY, $script, $mode, RedisIntegration::host(), $t->it->storePrefix, (string) $startAt, ...$args],
        };
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect($process)->not->toBeFalse();
        $processes[$n] = ['process' => $process, 'pipes' => $pipes];
    }

    $results = [];
    foreach ($processes as $n => $p) {
        $stdout = stream_get_contents($p['pipes'][1]);
        $stderr = stream_get_contents($p['pipes'][2]);
        expect(proc_close($p['process']))->toBe(0, "worker {$n} failed: {$stderr}");
        $decoded = json_decode(trim($stdout), true);
        expect($decoded)->toBeArray("worker {$n} printed: {$stdout}{$stderr}");
        $results[$n] = $decoded;
    }

    return $results;
}

it('hands out exactly the capacity of dispatch slots to eight racing processes', function () {
    $project = $this->project->id;
    $results = insightRace($this, array_map(fn () => ['slots', (string) $project, '40'], range(1, 8)));

    // max_active_runs_per_project is 3 in the child config: 320 attempts, never more than 3 winners
    expect(array_sum(array_column($results, 'got')))->toBe(3);
});

it('applies exactly one of two racing transitions to every run (done in one process, cancel in another)', function () {
    $store = new InsightRunStore($this->workerA);
    $ids = [];
    foreach (range(1, 40) as $i) {
        $ids[] = $id = InsightRunStore::newId();
        $store->create($id, InsightRunStore::TYPE_TRENDS, $this->project->id, 7, 'h', null, null);
        $store->markRunning($id);
    }

    $results = insightRace($this, [array_merge(['done'], $ids), array_merge(['cancel'], array_reverse($ids))]);

    $statuses = [];
    foreach ($ids as $id) {
        expect($results[0][$id] xor $results[1][$id])->toBeTrue("run {$id}: both or neither transition applied");
        $record = $store->find($id);
        $statuses[$record['status']] = ($statuses[$record['status']] ?? 0) + 1;
        // consistent record: an answer only with `done`, nothing with `cancelled`
        expect($record['status'])->toBe($results[0][$id] ? 'done' : 'cancelled')
            ->and($record['result'] !== null)->toBe($record['status'] === 'done');
    }
    expect(array_sum($statuses))->toBe(40);
});
