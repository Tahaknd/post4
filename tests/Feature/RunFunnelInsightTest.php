<?php

use App\Domain\Insights\InsightTypes;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Jobs\RunFunnelInsight;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeQueueJob;
use Tests\Support\FunnelFixtures as F;
use Tests\Support\ScriptedReads;

/*
 * The funnel run job. The behaviour it shares with the trends job (state machine, slots, busy handling, takeover) lives
 * in RunInsight and is pinned in RunTrendsInsightTest; this file proves that the funnel type runs through it and uses its
 * own keys.
 */

beforeEach(function () {
    config([
        'insights.cache_store' => 'array',
        'insights.cache_ttl_seconds' => 60,
        'insights.max_concurrent_queries_per_project' => 2,
        'insights.max_active_runs_per_project' => 5,
        'insights.max_active_runs_per_user' => 5,
    ]);
    Cache::store('array')->flush();
    $this->project = Project::factory()->create();
    $this->runs = app(InsightRunStore::class);
    $this->gate = app(InsightGate::class);
    $this->query = F::query(['view', 'buy']);
});

/** Queues the run record the way the controller does and returns [run id, run closure, fake queue job, client]. */
function startFunnelRun(object $t, ScriptedReads $client, int $attempt = 1): array
{
    $runId = InsightRunStore::newId();
    $t->gate->claimInflight($t->project->id, 7, $t->query->hash(), $runId);
    $projectSlot = $t->gate->acquireProjectSlot($t->project->id, $runId);
    $userSlot = $t->gate->acquireUserSlot(7, $runId);
    $t->runs->create($runId, InsightTypes::FUNNEL, $t->project->id, 7, $t->query->hash(), $projectSlot, $userSlot);

    $job = new RunFunnelInsight($t->project->id, $runId, $t->query->canonical(), 7, $projectSlot, $userSlot);
    $job->setJob($fake = new FakeQueueJob($attempt));

    return [$runId, fn () => $job->handle(F::runner($client, Cache::store('array')), $t->runs, $t->gate), $fake, $client];
}

it('runs the funnel, stores the result as done, caches it under the funnel key and frees the slots', function () {
    [$runId, $run, $fake, $client] = startFunnelRun($this, new ScriptedReads([F::levelRows([1 => 3, 2 => 2])]));

    $run();

    $record = $this->runs->find($runId);
    expect($record['status'])->toBe('done')
        ->and(array_column($record['result']['steps'], 'count'))->toBe([5, 2])
        ->and(count($client->calls))->toBe(1)
        ->and($fake->releasedWithDelay)->toBeNull()
        ->and(Cache::store('array')->has('insights:funnel:'.$this->project->id.':'.$this->query->hash()))->toBeTrue()
        ->and($this->gate->acquireProjectSlot($this->project->id, 'x'))->toBe(0)
        ->and($this->gate->inflightRunId($this->project->id, 7, $this->query->hash()))->toBeNull();
});

it('stores a failure as failed with the kind, never rethrows and leaves no raw text', function () {
    [$runId, $run, $fake] = startFunnelRun($this, new ScriptedReads([new ClickHouseException('Code: 241 SECRET-RAW', 241, ClickHouseErrorKind::MemoryLimit)]));

    $run();

    $record = $this->runs->find($runId);
    expect($record['status'])->toBe('failed')->and($record['error'])->toBe('memory_limit')
        ->and(json_encode(Cache::store('array')->get(InsightRunStore::key($runId))))->not->toContain('SECRET-RAW')
        ->and($fake->releasedWithDelay)->toBeNull()
        // the holder tells the waiters of THIS type and definition
        ->and($this->gate->flightFailure('funnel', $this->project->id, $this->query->hash()))->toBe(ClickHouseErrorKind::MemoryLimit)
        ->and($this->gate->flightFailure('trends', $this->project->id, $this->query->hash()))->toBeNull();
});

it('waits behind the single flight of the same funnel only, not behind a trends run with the same hash', function () {
    [$runId, $run, $fake] = startFunnelRun($this, new ScriptedReads([F::levelRows([1 => 1])]));
    $this->gate->acquireFlight('trends', $this->project->id, $this->query->hash(), 'a-trends-holder');
    $run();
    expect($this->runs->find($runId)['status'])->toBe('done');

    [$second, $runSecond, $fakeSecond] = startFunnelRun($this, new ScriptedReads([F::levelRows([1 => 1])]));
    Cache::store('array')->forget('insights:funnel:'.$this->project->id.':'.$this->query->hash());   // no shared result to copy
    $holder = $this->gate->acquireFlight('funnel', $this->project->id, $this->query->hash(), 'a-funnel-holder');
    $runSecond();

    expect($holder)->not->toBeNull()
        ->and($fakeSecond->releasedWithDelay)->toBe(1)
        ->and($this->runs->find($second)['status'])->toBe('queued');
});

it('copies the result of an identical funnel that finished meanwhile instead of querying', function () {
    [$first, $runFirst] = startFunnelRun($this, new ScriptedReads([F::levelRows([1 => 4, 2 => 1])]));
    $runFirst();
    [$second, $runSecond, , $client] = startFunnelRun($this, new ScriptedReads);

    $runSecond();

    expect($client->calls)->toBe([])
        ->and($this->runs->find($second)['status'])->toBe('done')
        ->and($this->runs->find($second)['result'])->toBe($this->runs->find($first)['result']);
});

it('fails a run whose stored definition is no longer valid with the generic kind, without querying', function () {
    $runId = InsightRunStore::newId();
    $this->runs->create($runId, InsightTypes::FUNNEL, $this->project->id, 7, 'h', null, null);
    $stale = [...$this->query->canonical(), 'v' => 99];
    $client = new ScriptedReads;

    $job = new RunFunnelInsight($this->project->id, $runId, $stale, 7);
    $job->setJob(new FakeQueueJob(1));
    $job->handle(F::runner($client, Cache::store('array')), $this->runs, $this->gate);

    expect($client->calls)->toBe([])
        ->and($this->runs->find($runId)['status'])->toBe('failed')
        ->and($this->runs->find($runId)['error'])->toBe('unknown');
});

it('tags the job with its project only: no value of the definition', function () {
    $job = new RunFunnelInsight(5, str_repeat('ab', 16), $this->query->canonical(), 9);

    expect($job->tags())->toBe(['insights', 'project:5'])->and($job->queue)->toBe('insights');
});
