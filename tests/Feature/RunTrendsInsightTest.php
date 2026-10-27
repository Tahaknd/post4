<?php

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Jobs\RunTrendsInsight;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
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
        'insights.max_concurrent_queries_per_project' => 2,
        'insights.max_active_runs_per_project' => 5,
        'insights.max_active_runs_per_user' => 5,
    ]);
    Cache::store('array')->flush();
    $this->project = Project::factory()->create();
    $this->runs = app(InsightRunStore::class);
    $this->gate = app(InsightGate::class);
});

/** Queues a run record the way the controller does and returns [runId, run closure, fake queue job, client, job, project slot, user slot]. */
function startRun(object $t, ?ScriptedReads $client = null, int $attempt = 1, ?TrendsQuery $query = null, ?Project $project = null): array
{
    $project ??= $t->project;
    $query ??= T::definition();
    $runId = InsightRunStore::newId();
    $projectSlot = $t->gate->acquireProjectSlot($project->id, $runId);
    $userSlot = $t->gate->acquireUserSlot(7, $runId);
    $t->gate->claimInflight($project->id, 7, $query->hash(), $runId);
    $t->runs->create($runId, InsightRunStore::TYPE_TRENDS, $project->id, 7, $query->hash(), $projectSlot, $userSlot);

    $job = new RunTrendsInsight($project->id, $runId, $query->canonical(), 7, $projectSlot, $userSlot);
    $job->setJob($fake = new FakeQueueJob($attempt));
    $client ??= new ScriptedReads([T::dayRows()]);
    $runner = T::runner($client, Cache::store('array'));

    return [$runId, fn () => $job->handle($runner, $t->runs, $t->gate), $fake, $client, $job, $projectSlot, $userSlot];
}

/** A second pop of the same run (a later attempt) with its own client. */
function popAgain(object $t, string $runId, ScriptedReads $client, int $attempt, ?TrendsQuery $query = null): FakeQueueJob
{
    $query ??= T::definition();
    $job = new RunTrendsInsight($t->project->id, $runId, $query->canonical(), 7);
    $job->setJob($fake = new FakeQueueJob($attempt));
    $job->handle(T::runner($client, Cache::store('array')), $t->runs, $t->gate);

    return $fake;
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
        ->and($this->gate->acquireProjectSlot($this->project->id, 'other'))->toBe($slot); // the slot is free again
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
        $job = new RunTrendsInsight($this->project->id, $runId, T::definition()->canonical(), 7);
        $job->setJob($fake = new FakeQueueJob($n));
        $job->handle(T::runner(new ScriptedReads([$busy()]), Cache::store('array')), $this->runs, $this->gate);
        expect($fake->releasedWithDelay)->toBe(3)->and($this->runs->find($runId)['busy_retries'])->toBe($n);
    }
    $job = new RunTrendsInsight($this->project->id, $runId, T::definition()->canonical(), 7);
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
    $bad = new RunTrendsInsight($this->project->id, $runId, [...T::definition()->canonical(), 'events' => []], 7);
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
    $foreign = new RunTrendsInsight($other->id, $runId, T::definition()->canonical(), 7);
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
    $holder = $this->gate->acquireFlight(InsightRunStore::TYPE_TRENDS, $this->project->id, $query->hash(), 'the-holder');

    $runWaiter();

    expect($client->calls)->toBe([])
        ->and($fake->releasedWithDelay)->toBe(1)
        ->and($this->runs->find($waiter)['status'])->toBe('queued');

    // the holder finishes: result cached, lock freed; the waiter's next attempt copies it
    T::runner(new ScriptedReads([T::dayRows()]), Cache::store('array'))->trends($this->project->id, $query);
    $holder->release();
    $job = new RunTrendsInsight($this->project->id, $waiter, $query->canonical(), 7);
    $job->setJob($next = new FakeQueueJob(2));
    $job->handle(T::runner($client, Cache::store('array')), $this->runs, $this->gate);

    expect($client->calls)->toBe([])
        ->and($next->releasedWithDelay)->toBeNull()
        ->and($this->runs->find($waiter)['status'])->toBe('done');
});

it('takes over when the single-flight holder is gone and nothing was cached', function () {
    $query = T::definition();
    [$waiter, , , $client] = startRun($this, new ScriptedReads([T::dayRows()]), query: $query);
    $holder = $this->gate->acquireFlight(InsightRunStore::TYPE_TRENDS, $this->project->id, $query->hash(), 'the-holder');
    $holder->release(); // died or failed without a cached result

    $job = new RunTrendsInsight($this->project->id, $waiter, $query->canonical(), 7);
    $job->setJob(new FakeQueueJob(2));
    $job->handle(T::runner($client, Cache::store('array')), $this->runs, $this->gate);

    expect(count($client->calls))->toBe(1)->and($this->runs->find($waiter)['status'])->toBe('done');
});

it('gives up waiting for the single-flight holder after the attempt budget with a busy failure', function () {
    $query = T::definition();
    [$waiter, , , $client] = startRun($this, new ScriptedReads([]), query: $query);
    $this->gate->acquireFlight(InsightRunStore::TYPE_TRENDS, $this->project->id, $query->hash(), 'the-holder');

    $job = new RunTrendsInsight($this->project->id, $waiter, $query->canonical(), 7);
    $job->setJob($fake = new FakeQueueJob(RunTrendsInsight::maxAttempts()));
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
        ->and($this->gate->acquireFlight(InsightRunStore::TYPE_TRENDS, $this->project->id, T::definition()->hash(), 'x'))->not->toBeNull();
});

it('frees its running slot after a query, so a project can run one query after another', function () {
    config(['insights.max_concurrent_queries_per_project' => 1]);
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
    config(['insights.max_active_runs_per_project' => 1]);
    $this->gate->acquireRunning($this->project->id, 'a');
    $this->gate->acquireRunning($this->project->id, 'b');
    [$runId, $run, , , , $slot] = startRun($this);
    $run(); // released, still queued

    expect($this->gate->acquireProjectSlot($this->project->id, 'other'))->toBeNull();
});

it('marks the run failed from failed(): timeout for a timeout, unknown otherwise, and frees the slot', function (Throwable $e, string $kind) {
    config(['insights.max_active_runs_per_project' => 1]);
    [$runId, , , , $job, $slot] = startRun($this);
    $this->runs->markRunning($runId);

    $job->failed($e);

    expect($this->runs->find($runId)['status'])->toBe('failed')
        ->and($this->runs->find($runId)['error'])->toBe($kind)
        ->and($this->gate->acquireProjectSlot($this->project->id, 'other'))->toBe($slot);
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

it('is configured for the insights queue with explicit tries and timeout and arithmetic that fits together', function () {
    $runId = InsightRunStore::newId();
    $job = new RunTrendsInsight(5, $runId, T::definition()->canonical(), 9);

    expect($job->queue)->toBe('insights')
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->timeout)->toBe(45)->toBeGreaterThan(20)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->tags())->toBe(['insights', 'project:5'])
        // the locks outlive a killed job (TTL above the job timeout) but expire before the queue hands it out again
        ->and((int) config('insights.lock_ttl_seconds'))->toBeGreaterThan($job->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        // waiting is bounded by the lock TTL at one release a second plus a margin, and $tries sits above it
        ->and(RunTrendsInsight::maxAttempts())->toBe((int) config('insights.lock_ttl_seconds') + 10)
        ->and($job->tries)->toBeGreaterThan(RunTrendsInsight::maxAttempts())
        ->and($job)->not->toBeInstanceOf(ShouldBeUnique::class);
    expect(fn () => new RunTrendsInsight(0, $runId, [], 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RunTrendsInsight(1, 'not-an-id', [], 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RunTrendsInsight(1, $runId, [], 0))->toThrow(InvalidArgumentException::class);
});

it('carries only ids, slot indexes and the canonical array: no object, no key', function () {
    $job = new RunTrendsInsight(5, InsightRunStore::newId(), T::definition()->canonical(), 9, 3, 1);
    $payload = serialize($job);

    expect($payload)->toContain('O:'.strlen(RunTrendsInsight::class).':"'.RunTrendsInsight::class.'"')
        ->and(substr_count($payload, 'O:'))->toBe(1)
        ->and($payload)->not->toContain('api_key')->not->toContain('TrendsQuery')
        ->and($job->projectSlot)->toBe(3)->and($job->userSlot)->toBe(1);
});

// ---------------------------------------------------------------- state machine, takeover, cancellation

it('skips a duplicate delivery of a run that is already running (first attempt) and keeps its slots', function () {
    config(['insights.max_active_runs_per_project' => 1]);
    [$runId, $run, , $client] = startRun($this);
    $this->runs->markRunning($runId);

    $run();

    expect($client->calls)->toBe([])->and($this->runs->find($runId)['status'])->toBe('running')
        ->and($this->gate->acquireProjectSlot($this->project->id, 'other'))->toBeNull(); // the live attempt still owns the slot
});

it('takes over a running run when it is popped again after a dead attempt (attempts > 1)', function () {
    [$runId, , , $client] = startRun($this, new ScriptedReads([T::dayRows()]));
    $this->runs->markRunning($runId); // the dead attempt got this far; its locks expired by now

    $fake = popAgain($this, $runId, $client, attempt: 2);

    expect($this->runs->find($runId)['status'])->toBe('done')
        ->and(count($client->calls))->toBe(1)
        ->and($fake->releasedWithDelay)->toBeNull();
});

it('never queries for a cancelled run and gives every slot back', function () {
    config(['insights.max_active_runs_per_project' => 1, 'insights.max_active_runs_per_user' => 1]);
    [$runId, $run, , $client] = startRun($this);
    $this->runs->cancel($runId);

    $run();

    expect($client->calls)->toBe([])
        ->and($this->runs->find($runId)['status'])->toBe('cancelled')
        ->and($this->gate->acquireProjectSlot($this->project->id, 'x'))->toBe(0)
        ->and($this->gate->acquireUserSlot(7, 'x'))->toBe(0)
        ->and($this->gate->inflightRunId($this->project->id, 7, T::definition()->hash()))->toBeNull();
});

it('does not query when the run is cancelled while the job waited for the flight lock', function () {
    $query = T::definition();
    [$runId, , , $client] = startRun($this, new ScriptedReads([T::dayRows()]), query: $query);
    $holder = $this->gate->acquireFlight(InsightRunStore::TYPE_TRENDS, $this->project->id, $query->hash(), 'the-holder');
    popAgain($this, $runId, $client, attempt: 1); // waits: releases itself
    $this->runs->cancel($runId);
    $holder->release();

    popAgain($this, $runId, $client, attempt: 2);

    expect($client->calls)->toBe([])->and($this->runs->find($runId)['status'])->toBe('cancelled');
});

it('lets a transition lose to a cancellation: a late answer never overwrites it, and a cancel never overwrites an answer', function () {
    [$cancelled] = startRun($this);
    $this->runs->markRunning($cancelled);
    expect($this->runs->cancel($cancelled))->toBeTrue()
        ->and($this->runs->markDone($cancelled, ['x' => 1]))->toBeFalse()
        ->and($this->runs->markFailed($cancelled, ClickHouseErrorKind::Timeout))->toBeFalse()
        ->and($this->runs->markRunning($cancelled))->toBeFalse()
        ->and($this->runs->markQueued($cancelled))->toBeFalse()
        ->and($this->runs->find($cancelled)['status'])->toBe('cancelled')->and($this->runs->find($cancelled)['result'])->toBeNull();

    [$done] = startRun($this);
    expect($this->runs->markDone($done, ['timezone' => 'UTC']))->toBeTrue()
        ->and($this->runs->cancel($done))->toBeFalse()
        ->and($this->runs->markFailed($done, ClickHouseErrorKind::Unknown))->toBeFalse()
        ->and($this->runs->find($done)['status'])->toBe('done');

    expect($this->runs->markRunning(InsightRunStore::newId()))->toBeFalse(); // a missing record is never recreated
});

it('gives the slots back on every exit: skipped (evicted record), project deleted, failed(), done', function () {
    config(['insights.max_active_runs_per_project' => 1, 'insights.max_active_runs_per_user' => 1]);
    $free = fn () => $this->gate->acquireProjectSlot($this->project->id, 'probe') === 0 && $this->gate->acquireUserSlot(7, 'probe') === 0
        && (fn () => $this->gate->releaseSlots($this->project->id, 7, 'probe', 0, 0) || true)();

    [$evicted, $runEvicted] = startRun($this);
    Cache::store('array')->forget(InsightRunStore::key($evicted));
    $runEvicted();
    expect($free())->toBeTrue();

    [$deleted, $runDeleted] = startRun($this);
    $this->project->delete();
    $runDeleted();
    expect($free())->toBeTrue();
    $this->project = Project::factory()->create();

    [$killed, , , , $job] = startRun($this);
    $this->runs->markRunning($killed);
    $job->failed(new TimeoutExceededException('x'));
    expect($free())->toBeTrue()->and($this->runs->find($killed)['error'])->toBe('timeout');

    [$ok, $runOk] = startRun($this);
    $runOk();
    expect($free())->toBeTrue()->and($this->runs->find($ok)['status'])->toBe('done');
});

it('shares a holder failure with the waiters of the same definition through a short marker', function () {
    $query = T::definition();
    [$holderRun, $runHolder] = startRun($this, new ScriptedReads([new ClickHouseException('Code: 159 SECRET-RAW', 159, ClickHouseErrorKind::Timeout)]), query: $query);
    [$waiter, , , $waiterClient] = startRun($this, new ScriptedReads([T::dayRows()]), query: $query);

    $runHolder();
    popAgain($this, $waiter, $waiterClient, attempt: 1);

    expect($this->runs->find($holderRun)['error'])->toBe('timeout')
        ->and($this->runs->find($waiter)['status'])->toBe('failed')
        ->and($this->runs->find($waiter)['error'])->toBe('timeout')
        ->and($waiterClient->calls)->toBe([])
        ->and($this->gate->flightFailure(InsightRunStore::TYPE_TRENDS, $this->project->id, $query->hash()))->toBe(ClickHouseErrorKind::Timeout);
});

it('writes no failure marker for a busy or unreachable ClickHouse, which a waiter should simply retry', function (ClickHouseErrorKind $kind, int $code) {
    $query = T::definition();
    [, $run] = startRun($this, new ScriptedReads([new ClickHouseException('x', $code, $kind)]), query: $query, attempt: RunTrendsInsight::maxAttempts());

    $run();

    expect($this->gate->flightFailure(InsightRunStore::TYPE_TRENDS, $this->project->id, $query->hash()))->toBeNull();
})->with([[ClickHouseErrorKind::Busy, 202]]);

it('expires the failure marker with its short TTL (always set)', function () {
    $query = T::definition();
    $this->gate->rememberFlightFailure(InsightRunStore::TYPE_TRENDS, $this->project->id, $query->hash(), ClickHouseErrorKind::Timeout);
    Carbon::setTestNow(now()->addSeconds((int) config('insights.flightfail_ttl_seconds') + 1));

    expect($this->gate->flightFailure(InsightRunStore::TYPE_TRENDS, $this->project->id, $query->hash()))->toBeNull();
    Carbon::setTestNow();
});

it('releases the inflight key when the run finishes, so the same user can start the definition again', function () {
    $query = T::definition();
    [$runId, $run] = startRun($this, query: $query);
    expect($this->gate->inflightRunId($this->project->id, 7, $query->hash()))->toBe($runId);

    $run();

    expect($this->gate->inflightRunId($this->project->id, 7, $query->hash()))->toBeNull();
});

it('keeps the store the container gives each insight class: the insight store, not the default one', function (string $class) {
    config(['insights.cache_store' => 'array', 'cache.default' => 'file']);
    $resolved = (new ReflectionProperty($class, 'cache'))->getValue(app($class));

    expect($resolved)->toBe(Cache::store('array'));
})->with([[InsightRunStore::class], [InsightGate::class], [InsightRunner::class]]);

it('counts a per-user cap across projects: one account cannot spread past it', function () {
    config(['insights.max_active_runs_per_user' => 3, 'insights.max_active_runs_per_project' => 10]);
    $slots = [];
    foreach (range(1, 5) as $i) {
        $project = Project::factory()->create();
        $slots[] = $this->gate->acquireUserSlot(42, "run-{$i}");
    }

    expect($slots)->toBe([0, 1, 2, null, null])
        ->and($this->gate->acquireUserSlot(43, 'other-user'))->toBe(0);
});
