<?php

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStarter;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Domain\Insights\Runs\RunStart;
use App\Domain\Insights\Runs\StartOptions;
use App\Domain\Insights\Trends\TrendsResult;
use App\Jobs\RunInsight;
use App\Jobs\RunTrendsInsight;
use App\Models\Project;
use App\Support\RateLimit\LimiterOutage;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FailingCacheStore;
use Tests\Support\FakeQueueJob;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

/*
 * The one flow of asking for a run (the controllers and the scheduled refresh both go through it). The HTTP contract on
 * top of it is pinned by InsightApiTest, FunnelApiTest and RetentionApiTest; here: the scheduler as the owner, the
 * options and the dashboard result cache.
 */

beforeEach(function () {
    config([
        'insights.cache_store' => 'array',
        'insights.cache_ttl_seconds' => 60,
        'insights.max_active_runs_per_project' => 20,
        'insights.max_active_runs_per_user' => 8,
        'dashboards.refresh.result_ttl_seconds' => 2100,
    ]);
    Cache::store('array')->flush();
    LimiterOutage::reset();
    InsightRunner::resetWarningThrottle();
    $this->project = Project::factory()->create();
    $this->starter = app(InsightRunStarter::class);
    $this->gate = app(InsightGate::class);
    $this->runs = app(InsightRunStore::class);
    Queue::fake();
});

it('queues a run for a user: record, user slot, project slot and one job on the insights queue', function () {
    $start = $this->starter->start($this->project->id, 7, T::definition());

    expect($start->outcome)->toBe(RunStart::QUEUED);
    $record = $this->runs->find($start->runId);
    expect($record['user_id'])->toBe(7)->and($record['status'])->toBe('queued')
        ->and($record['project_slot'])->toBe(0)->and($record['user_slot'])->toBe(0);
    Queue::assertPushedOn('insights', RunTrendsInsight::class, fn (RunInsight $job) => $job->userId === 7 && $job->forDashboard === false);
});

it('gives a scheduled run no user slot, only a project slot, under user 0', function () {
    $start = $this->starter->start($this->project->id, InsightRunStarter::SYSTEM_USER_ID, T::definition());

    $record = $this->runs->find($start->runId);
    expect($start->outcome)->toBe(RunStart::QUEUED)
        ->and($record['user_id'])->toBe(0)->and($record['user_slot'])->toBeNull()->and($record['project_slot'])->toBe(0);
    // nobody's interactive allowance was touched: user 0 owns no slot key at all
    expect(Cache::store('array')->get(InsightGate::userSlotKey(0, 0)))->toBeNull();
    Queue::assertPushed(RunTrendsInsight::class, fn (RunInsight $job) => $job->userId === 0 && $job->userSlot === null);
});

it('answers an identical request again with the same live run, for a user and for the scheduler', function (int $userId) {
    $first = $this->starter->start($this->project->id, $userId, T::definition());
    $second = $this->starter->start($this->project->id, $userId, T::definition());

    expect($second->outcome)->toBe(RunStart::QUEUED)->and($second->runId)->toBe($first->runId);
    Queue::assertPushed(RunTrendsInsight::class, 1);
})->with([7, InsightRunStarter::SYSTEM_USER_ID]);

it('keeps the runs of two owners for the same definition apart', function () {
    $a = $this->starter->start($this->project->id, 7, T::definition());
    $b = $this->starter->start($this->project->id, InsightRunStarter::SYSTEM_USER_ID, T::definition());

    expect($a->runId)->not->toBe($b->runId);
    Queue::assertPushed(RunTrendsInsight::class, 2);
});

it('serves a fresh cached result without queuing anything', function () {
    $runner = T::runner(new ScriptedReads([T::dayRows()]), Cache::store('array'));
    $runner->run($this->project->id, T::definition());

    $start = $this->starter->start($this->project->id, 7, T::definition());

    expect($start->outcome)->toBe(RunStart::CACHED)->and($start->result)->toBeInstanceOf(TrendsResult::class);
    Queue::assertNothingPushed();
});

it('refuses with busy when the project is full, also for the scheduler, and leaves nothing behind', function () {
    config(['insights.max_active_runs_per_project' => 1]);
    $this->starter->start($this->project->id, 7, T::definition('a'));

    $busy = $this->starter->start($this->project->id, InsightRunStarter::SYSTEM_USER_ID, T::definition('b'));

    expect($busy->outcome)->toBe(RunStart::BUSY)->and($busy->message)->toContain('project');
    Queue::assertPushed(RunTrendsInsight::class, 1);
    // the inflight key of the refused one was released: it can be asked for again once there is room
    expect($this->gate->inflightRunId($this->project->id, 0, T::definition('b')->hash()))->toBeNull();
});

it('refuses with busy when the user has too many runs, and does not apply that cap to the scheduler', function () {
    config(['insights.max_active_runs_per_user' => 1]);
    $this->starter->start($this->project->id, 7, T::definition('a'));

    $busy = $this->starter->start($this->project->id, 7, T::definition('b'));
    $system = $this->starter->start($this->project->id, InsightRunStarter::SYSTEM_USER_ID, T::definition('c'));
    $system2 = $this->starter->start($this->project->id, InsightRunStarter::SYSTEM_USER_ID, T::definition('d'));

    expect($busy->outcome)->toBe(RunStart::BUSY)->and($busy->message)->toContain('You have too many')
        ->and($system->outcome)->toBe(RunStart::QUEUED)->and($system2->outcome)->toBe(RunStart::QUEUED);
});

it('is unavailable, and releases what it took, when the store or the queue breaks', function () {
    Queue::shouldReceive('push')->never();
    app()->instance(InsightRunStore::class, new InsightRunStore(new Repository(new FailingCacheStore)));
    $starter = new InsightRunStarter(app(InsightRunner::class), app(InsightRunStore::class), $this->gate);

    $start = $starter->start($this->project->id, 7, T::definition());

    expect($start->outcome)->toBe(RunStart::UNAVAILABLE);
    expect($this->gate->acquireProjectSlot($this->project->id, 'probe'))->toBe(0)
        ->and($this->gate->acquireUserSlot(7, 'probe'))->toBe(0);
});

it('queues the refresh options: its own queue, a delay and the dashboard flag', function () {
    $this->starter->start($this->project->id, InsightRunStarter::SYSTEM_USER_ID, T::definition(), new StartOptions(forDashboard: true, queue: 'insights-refresh', delaySeconds: 30));

    Queue::assertPushedOn('insights-refresh', RunTrendsInsight::class, fn (RunInsight $job) => $job->forDashboard === true && $job->delay === 30);
});

it('does not delay or move an interactive run', function () {
    $this->starter->start($this->project->id, 7, T::definition());

    Queue::assertPushedOn('insights', RunTrendsInsight::class, fn (RunInsight $job) => $job->delay === null && $job->forDashboard === false);
});

// ---------------------------------------------------------------- the job and the dashboard cache

function runJobNow(object $t, bool $forDashboard, ?ScriptedReads $client = null, int $userId = 0): void
{
    $query = T::definition();
    $runId = InsightRunStore::newId();
    $t->runs->create($runId, 'trends', $t->project->id, $userId, $query->hash(), null, null);
    $job = new RunTrendsInsight($t->project->id, $runId, $query->canonical(), $userId);
    $job->forDashboard = $forDashboard;
    $job->setJob(new FakeQueueJob(1));
    $job->handle(T::runner($client ?? new ScriptedReads([T::dayRows()]), Cache::store('array')), $t->runs, $t->gate);
}

it('stores the result of a dashboard run in the dashboard cache with its computed time, and an ordinary run does not', function () {
    $runner = T::runner(new ScriptedReads, Cache::store('array'));
    $this->travelTo('2026-03-15 10:00:00');

    runJobNow($this, forDashboard: false);
    expect($runner->cachedForDashboard($this->project->id, T::definition()))->toBeNull();

    Cache::store('array')->flush();
    runJobNow($this, forDashboard: true);
    $stored = $runner->cachedForDashboard($this->project->id, T::definition());

    expect($stored->result->toArray()['series'][0]['data'])->toBe([4, 0, 9])
        ->and($stored->computedAt)->toBe(now()->timestamp);
});

it('keeps the dashboard cache for its own TTL, long after the interactive entry expired', function () {
    config(['insights.cache_ttl_seconds' => 60, 'dashboards.refresh.result_ttl_seconds' => 2100]);
    $this->travelTo('2026-03-15 10:00:00');
    runJobNow($this, forDashboard: true);
    $runner = T::runner(new ScriptedReads, Cache::store('array'));

    $this->travelTo('2026-03-15 10:01:30');
    expect($runner->cached($this->project->id, T::definition()))->toBeNull()
        ->and($runner->cachedForDashboard($this->project->id, T::definition()))->not->toBeNull();

    $this->travelTo('2026-03-15 10:34:00');
    expect($runner->cachedForDashboard($this->project->id, T::definition()))->not->toBeNull();

    $this->travelTo('2026-03-15 10:36:00');
    expect($runner->cachedForDashboard($this->project->id, T::definition()))->toBeNull();
});

it('does not use the dashboard cache when its TTL is 0', function () {
    config(['dashboards.refresh.result_ttl_seconds' => 0]);
    runJobNow($this, forDashboard: true);

    expect(T::runner(new ScriptedReads, Cache::store('array'))->cachedForDashboard($this->project->id, T::definition()))->toBeNull();
});

it('keeps the dashboard cache per project and per definition', function () {
    runJobNow($this, forDashboard: true);
    $runner = T::runner(new ScriptedReads, Cache::store('array'));

    expect($runner->cachedForDashboard($this->project->id + 1, T::definition()))->toBeNull()
        ->and($runner->cachedForDashboard($this->project->id, T::definition('other')))->toBeNull();
});

it('does not throw when the dashboard cache is down', function () {
    $runner = T::runner(new ScriptedReads, new Repository(new FailingCacheStore));

    expect($runner->cachedForDashboard(1, T::definition()))->toBeNull();
    $runner->rememberForDashboard(1, T::definition(), T::runner(new ScriptedReads([T::dayRows()]), new Repository(new ArrayStore))->run(1, T::definition()));
    expect(true)->toBeTrue();
});

it('finishes a dashboard run from the interactive cache and still fills the dashboard cache', function () {
    $runner = T::runner(new ScriptedReads([T::dayRows()]), Cache::store('array'));
    $runner->run($this->project->id, T::definition());

    $client = new ScriptedReads;
    runJobNow($this, forDashboard: true, client: $client);

    expect($client->calls)->toBe([])
        ->and($runner->cachedForDashboard($this->project->id, T::definition()))->not->toBeNull();
});

it('serialises the dashboard flag only when it is set, so a payload from before it existed reads as an ordinary run', function () {
    $job = new RunTrendsInsight(5, str_repeat('ab', 16), T::definition()->canonical(), 0);
    expect(serialize($job))->not->toContain('forDashboard')->and(unserialize(serialize($job))->forDashboard)->toBeFalse();

    $job->forDashboard = true;
    expect(unserialize(serialize($job))->forDashboard)->toBeTrue()
        ->and(unserialize(serialize($job))->userId)->toBe(0);
});
