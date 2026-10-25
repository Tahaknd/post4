<?php

use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Jobs\RunTrendsInsight;
use App\Models\Project;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\FakeQueueJob;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

/*
 * The RunTrendsInsight job with a scripted ClickHouse: outcomes stored in the run record, never a rethrown query
 * failure, release behaviour, single flight, the per-project running cap. The cache store is the in-memory array
 * store (Redis behaviour: InsightRunStoreRedisTest, group redis).
 */

beforeEach(function () {
    config([
        'insights.cache_store' => 'array',
        'insights.cache_ttl_seconds' => 60,
        'insights.cache_warning_interval_seconds' => 60,
        'insights.max_running_per_project' => 2,
        'insights.max_runs_per_project' => 5,
    ]);
    Cache::store('array')->flush();
    $this->project = Project::factory()->create();
    $this->runs = new InsightRunStore;
    $this->gate = new InsightGate;
});

/** Queues a run record the way the controller does and returns [runId, job, query]. */
function startRun(object $t, ?ScriptedReads $client = null, int $attempt = 1, ?TrendsQuery $query = null, ?Project $project = null): array
{
    $project ??= $t->project;
    $query ??= T::definition();
    $id = $project->id;
    $runId = InsightRunStore::newId();
    $slot = $t->gate->acquireDispatchSlot($id, $runId);
    $t->runs->create($runId, $id, 7, $query->hash(), $slot);

    $job = new RunTrendsInsight($id, $runId, $query->canonical());
    $job->setJob($fake = new FakeQueueJob($attempt));
    $client ??= new ScriptedReads([T::dayRows()]);
    $runner = T::runner($client, Cache::store('array'));

    return [$runId, fn () => $job->handle($runner, $t->runs, $t->gate), $fake, $client, $job, $slot];
}

it('queries, stores the result as done, frees the dispatch slot and never touches the user', function () {
    [$runId, $run, $fake, $client, , $slot] = startRun($this);

    $run();

    $record = $this->runs->find($runId);
    expect($record['status'])->toBe('done')
        ->and($record['error'])->toBeNull()
        ->and($record['result']['series'][0]['data'])->toBe([4, 0, 9])
        ->and(count($client->calls))->toBe(1)
        ->and($fake->releasedWithDelay)->toBeNull()
        ->and($this->gate->acquireDispatchSlot($this->project->id, 'other'))->toBe($slot); // the slot is free again
});

it('stores every ClickHouse failure kind as failed with the kind, does not rethrow and keeps raw text out of the record', function (ClickHouseErrorKind $kind, int $code) {
    $raw = "Code: {$code}. DB::Exception: SECRET-RAW SELECT password FROM users";
    [$runId, $run, $fake] = startRun($this, new ScriptedReads([new ClickHouseException($raw, $code, $kind)]));

    $run();

    $record = $this->runs->find($runId);
    expect($record['status'])->toBe('failed')
        ->and($record['error'])->toBe($kind->value)
        ->and($record['result'])->toBeNull()
        ->and(json_encode(Cache::store('array')->get(InsightRunStore::key($runId))))->not->toContain('SECRET-RAW');
    // Busy is the one kind that is handed back first (see the busy tests); everything else ends here.
    if ($kind !== ClickHouseErrorKind::Busy) {
        expect($fake->releasedWithDelay)->toBeNull();
    }
})->with([
    'timeout' => [ClickHouseErrorKind::Timeout, 159],
    'memory' => [ClickHouseErrorKind::MemoryLimit, 241],
    'rows' => [ClickHouseErrorKind::TooManyRows, 396],
    'constraint' => [ClickHouseErrorKind::ConstraintViolation, 452],
    'auth' => [ClickHouseErrorKind::AuthenticationFailed, 516],
    'unknown' => [ClickHouseErrorKind::Unknown, 999],
]);

it('hands a busy ClickHouse back with a 3 second release and keeps the run queued, then fails with busy after three releases', function () {
    $busy = fn () => new ClickHouseException('Code: 202 SECRET-RAW', 202, ClickHouseErrorKind::Busy);
    [$runId, $run, $fake] = startRun($this, new ScriptedReads([$busy()]));

    $run();

    expect($fake->releasedWithDelay)->toBe(3)
        ->and($this->runs->find($runId)['status'])->toBe('queued')
        ->and($this->runs->find($runId)['busy_retries'])->toBe(1);

    // retries 1 -> 2 -> 3, then the fourth busy answer is final
    foreach ([2, 3] as $n) {
        $job = new RunTrendsInsight($this->project->id, $runId, T::definition()->canonical());
        $job->setJob($fake = new FakeQueueJob($n));
        $job->handle(T::runner(new ScriptedReads([$busy()]), Cache::store('array')), $this->runs, $this->gate);
        expect($fake->releasedWithDelay)->toBe(3)->and($this->runs->find($runId)['busy_retries'])->toBe($n);
    }
    $job = new RunTrendsInsight($this->project->id, $runId, T::definition()->canonical());
    $job->setJob($fake = new FakeQueueJob(4));
    $job->handle(T::runner(new ScriptedReads([$busy()]), Cache::store('array')), $this->runs, $this->gate);

    expect($fake->releasedWithDelay)->toBeNull()
        ->and($this->runs->find($runId)['status'])->toBe('failed')
        ->and($this->runs->find($runId)['error'])->toBe('busy');
});

it('stores an unexpected exception as unknown, reports it by class and ids only and does not rethrow', function () {
    Exceptions::fake();
    [$runId, $run] = startRun($this, new ScriptedReads([new RuntimeException('PRIVATE-DETAIL select * from secrets')]));

    $run();

    expect($this->runs->find($runId)['error'])->toBe('unknown');
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), $runId)
        && str_contains($e->getMessage(), RuntimeException::class)
        && ! str_contains($e->getMessage(), 'PRIVATE-DETAIL'));
});

it('fails a definition that no longer passes the constructor rules as unknown without querying', function () {
    [$runId, , , $client, $job] = startRun($this);
    $bad = new RunTrendsInsight($this->project->id, $runId, [...T::definition()->canonical(), 'events' => []]);
    $bad->setJob(new FakeQueueJob);

    $bad->handle(T::runner($client, Cache::store('array')), $this->runs, $this->gate);

    expect($this->runs->find($runId)['status'])->toBe('failed')
        ->and($this->runs->find($runId)['error'])->toBe('unknown')
        ->and($client->calls)->toBe([]);
});

it('skips a run whose record expired, belongs to another project or is no longer queued, and a deleted project', function () {
    [$runId, $run, , $client] = startRun($this);
    Cache::store('array')->forget(InsightRunStore::key($runId));
    $run();
    expect($client->calls)->toBe([]);

    [$runId, , , $client, $job] = startRun($this);
    $other = Project::factory()->create();
    $foreign = new RunTrendsInsight($other->id, $runId, T::definition()->canonical());
    $foreign->setJob(new FakeQueueJob);
    $foreign->handle(T::runner($client, Cache::store('array')), $this->runs, $this->gate);
    expect($client->calls)->toBe([])->and($this->runs->find($runId)['status'])->toBe('queued');

    $this->runs->markRunning($runId);
    $job->handle(T::runner($client, Cache::store('array')), $this->runs, $this->gate);
    expect($client->calls)->toBe([]);

    [$runId, $run, , $client] = startRun($this);
    $this->project->delete();
    $run();
    expect($client->calls)->toBe([])->and($this->runs->find($runId))->toBeNull();
});

it('copies a result another run of the same definition already cached without a query', function () {
    [$first, $runFirst, , $clientFirst] = startRun($this);
    $runFirst();
    [$second, $runSecond, , $clientSecond] = startRun($this, new ScriptedReads([]));

    $runSecond();

    expect(count($clientFirst->calls))->toBe(1)
        ->and($clientSecond->calls)->toBe([])
        ->and($this->runs->find($second)['result'])->toBe($this->runs->find($first)['result']);
});

it('lets only the holder of the single-flight lock query: another run waits with a 1 second release and then copies the result', function () {
    $query = T::definition();
    [$waiter, $runWaiter, $fake, $client] = startRun($this, new ScriptedReads([]), query: $query);
    $holder = $this->gate->acquireFlight($this->project->id, $query->hash(), 'the-holder');

    $runWaiter();

    expect($client->calls)->toBe([])
        ->and($fake->releasedWithDelay)->toBe(1)
        ->and($this->runs->find($waiter)['status'])->toBe('queued');

    // the holder finishes: result cached, lock freed; the waiter's next attempt copies it
    T::runner(new ScriptedReads([T::dayRows()]), Cache::store('array'))->trends($this->project->id, $query);
    $holder->release();
    $job = new RunTrendsInsight($this->project->id, $waiter, $query->canonical());
    $job->setJob($next = new FakeQueueJob(2));
    $job->handle(T::runner($client, Cache::store('array')), $this->runs, $this->gate);

    expect($client->calls)->toBe([])
        ->and($next->releasedWithDelay)->toBeNull()
        ->and($this->runs->find($waiter)['status'])->toBe('done');
});

it('takes over when the single-flight holder is gone and nothing was cached', function () {
    $query = T::definition();
    [$waiter, , , $client] = startRun($this, new ScriptedReads([T::dayRows()]), query: $query);
    $holder = $this->gate->acquireFlight($this->project->id, $query->hash(), 'the-holder');
    $holder->release(); // died or failed without a cached result

    $job = new RunTrendsInsight($this->project->id, $waiter, $query->canonical());
    $job->setJob(new FakeQueueJob(2));
    $job->handle(T::runner($client, Cache::store('array')), $this->runs, $this->gate);

    expect(count($client->calls))->toBe(1)->and($this->runs->find($waiter)['status'])->toBe('done');
});

it('gives up waiting for the single-flight holder after the attempt budget with a busy failure', function () {
    $query = T::definition();
    [$waiter, , , $client] = startRun($this, new ScriptedReads([]), query: $query);
    $this->gate->acquireFlight($this->project->id, $query->hash(), 'the-holder');

    $job = new RunTrendsInsight($this->project->id, $waiter, $query->canonical());
    $job->setJob($fake = new FakeQueueJob(RunTrendsInsight::MAX_ATTEMPTS));
    $job->handle(T::runner($client, Cache::store('array')), $this->runs, $this->gate);

    expect($fake->releasedWithDelay)->toBeNull()
        ->and($this->runs->find($waiter)['error'])->toBe('busy')
        ->and($client->calls)->toBe([]);
});

it('releases itself for 3 seconds when the project already runs its maximum of queries, and frees the flight lock', function () {
    $this->gate->acquireRunning($this->project->id, 'a');
    $this->gate->acquireRunning($this->project->id, 'b');
    [$runId, $run, $fake, $client] = startRun($this);

    $run();

    expect($fake->releasedWithDelay)->toBe(3)
        ->and($client->calls)->toBe([])
        ->and($this->runs->find($runId)['status'])->toBe('queued')
        // the flight lock was given back, so an identical run is not blocked behind a job that never queried
        ->and($this->gate->acquireFlight($this->project->id, T::definition()->hash(), 'x'))->not->toBeNull();
});

it('frees its running slot after a query, so a project can run one query after another', function () {
    config(['insights.max_running_per_project' => 1]);
    [, $first] = startRun($this);
    [$second, $runSecond, $fake] = startRun($this, new ScriptedReads([T::dayRows('purchase')]), query: T::definition('purchase'));

    $first();
    $runSecond();

    expect($this->runs->find($second)['status'])->toBe('done')->and($fake->releasedWithDelay)->toBeNull();
});

it('does not hold the running cap for another project', function () {
    $other = Project::factory()->create();
    $this->gate->acquireRunning($this->project->id, 'a');
    $this->gate->acquireRunning($this->project->id, 'b');
    [$runId, $run, $fake] = startRun($this, project: $other);

    $run();

    expect($this->runs->find($runId)['status'])->toBe('done')->and($fake->releasedWithDelay)->toBeNull();
});

it('keeps the dispatch slot while it is released and frees it when the run ends', function () {
    config(['insights.max_runs_per_project' => 1]);
    $this->gate->acquireRunning($this->project->id, 'a');
    $this->gate->acquireRunning($this->project->id, 'b');
    [$runId, $run, , , , $slot] = startRun($this);
    $run(); // released, still queued

    expect($this->gate->acquireDispatchSlot($this->project->id, 'other'))->toBeNull();
});

it('marks the run failed from failed(): timeout for a timeout, unknown otherwise, and frees the slot', function (Throwable $e, string $kind) {
    config(['insights.max_runs_per_project' => 1]);
    [$runId, , , , $job, $slot] = startRun($this);
    $this->runs->markRunning($runId);

    $job->failed($e);

    expect($this->runs->find($runId)['status'])->toBe('failed')
        ->and($this->runs->find($runId)['error'])->toBe($kind)
        ->and($this->gate->acquireDispatchSlot($this->project->id, 'other'))->toBe($slot);
})->with([
    'timeout' => [fn () => new TimeoutExceededException('x'), 'timeout'],
    'attempts' => [fn () => new MaxAttemptsExceededException('SECRET'), 'unknown'],
    'other' => [fn () => new RuntimeException('SECRET'), 'unknown'],
]);

it('leaves a finished run alone when failed() comes late', function () {
    [$runId, $run, , , $job] = startRun($this);
    $run();

    $job->failed(new RuntimeException('late'));

    expect($this->runs->find($runId)['status'])->toBe('done');
});

it('is configured for the insights queue with explicit tries, timeout and a unique key per project and run', function () {
    $runId = InsightRunStore::newId();
    $job = new RunTrendsInsight(5, $runId, T::definition()->canonical());

    expect($job->queue)->toBe('insights')
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->tries)->toBeGreaterThan(RunTrendsInsight::MAX_ATTEMPTS)
        ->and($job->timeout)->toBe(45)->toBeGreaterThan(20)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->uniqueId())->toBe("insights-run:5:{$runId}")
        ->and($job->tags())->toBe(['insights', 'project:5']);
    expect(fn () => new RunTrendsInsight(0, $runId, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RunTrendsInsight(1, 'not-an-id', []))->toThrow(InvalidArgumentException::class);
});

it('carries only ids and the canonical array: no object, no user, no key', function () {
    $job = new RunTrendsInsight(5, InsightRunStore::newId(), T::definition()->canonical());
    $payload = serialize($job);

    expect($payload)->toContain('O:'.strlen(RunTrendsInsight::class).':"'.RunTrendsInsight::class.'"')
        ->and(substr_count($payload, 'O:'))->toBe(1)
        ->and($payload)->not->toContain('api_key')->not->toContain('TrendsQuery');
});
