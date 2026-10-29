<?php

use App\Domain\Insights\InsightTypes;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Jobs\RunInsight;
use App\Jobs\RunTrendsInsight;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeQueueJob;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

/*
 * The generic run job and its compatibility with the job as it was before the type registry. The behaviour of the job
 * itself (state machine, slots, single flight, busy handling) is pinned for trends in RunTrendsInsightTest and, for the
 * funnel, in RunFunnelInsightTest; this file is about what is shared and about deployments.
 */

/** The `command` of a RunTrendsInsight payload exactly as the code BEFORE the generic job serialized it (captured from the Redis payload). */
const LEGACY_TRENDS_COMMAND = 'O:25:"App\Jobs\RunTrendsInsight":7:{s:9:"projectId";i:5;s:5:"runId";s:32:"abababababababababababababababab";s:9:"canonical";a:8:{s:1:"v";i:2;s:6:"events";a:1:{i:0;s:6:"signup";}s:6:"metric";s:12:"total_events";s:5:"range";a:2:{s:4:"from";s:24:"2026-03-01T00:00:00.000Z";s:2:"to";s:24:"2026-03-04T00:00:00.000Z";}s:8:"interval";s:3:"day";s:8:"timezone";s:3:"UTC";s:9:"breakdown";N;s:7:"filters";a:0:{}}s:6:"userId";i:9;s:11:"projectSlot";i:3;s:8:"userSlot";i:1;s:5:"queue";s:8:"insights";}';

beforeEach(function () {
    config(['insights.cache_store' => 'array', 'insights.cache_ttl_seconds' => 60]);
    Cache::store('array')->flush();
});

it('runs a trends job that was queued by the code before the generic job, from its old payload', function () {
    Project::factory()->create(['id' => 5]);
    $runs = app(InsightRunStore::class);
    $gate = app(InsightGate::class);
    $runs->create('abababababababababababababababab', InsightRunStore::TYPE_TRENDS, 5, 9, T::definition()->hash(), 3, 1);

    $job = unserialize(LEGACY_TRENDS_COMMAND);
    expect($job)->toBeInstanceOf(RunTrendsInsight::class)->toBeInstanceOf(RunInsight::class)
        ->and($job->queue)->toBe('insights')
        ->and($job->projectId)->toBe(5)
        ->and($job->userId)->toBe(9);

    $job->setJob($fake = new FakeQueueJob(1));
    $job->handle(T::runner(new ScriptedReads([T::dayRows()]), Cache::store('array')), $runs, $gate);

    $record = $runs->find('abababababababababababababababab');
    expect($record['status'])->toBe('done')
        ->and($record['result']['series'][0]['data'])->toBe([4, 0, 9])
        ->and($fake->releasedWithDelay)->toBeNull();
});

it('still queues trends runs as RunTrendsInsight with the same payload shape, so a worker running the old code can run them', function () {
    $query = T::definition();
    $job = new RunTrendsInsight(5, str_repeat('ab', 16), $query->canonical(), 9, 3, 1);

    $serialized = serialize($job);

    expect($serialized)->toBe(LEGACY_TRENDS_COMMAND)
        ->and($job->tags())->toBe(['insights', 'project:5']);
});

/** Per registered type: a sample definition and the job class that must run it. */
function jobSamples(): array
{
    return [
        InsightTypes::TRENDS => [fn () => T::definition(), RunTrendsInsight::class],
    ];
}

it('has a job class and a sample for every registered insight type', function () {
    expect(array_keys(jobSamples()))->toEqualCanonicalizing(InsightTypes::keys());
});

it('queues the job class of the type on the insights queue with the canonical definition only', function (string $type) {
    [$sample, $class] = jobSamples()[$type];
    $query = $sample();
    Queue::fake();

    RunInsight::dispatchFor($query, 5, str_repeat('cd', 16), 9, 2, 1);

    Queue::assertPushedOn('insights', $class, fn (RunInsight $job) => $job::class === $class
        && $job->canonical === $query->canonical()
        && [$job->projectId, $job->userId, $job->projectSlot, $job->userSlot] === [5, 9, 2, 1]);
})->with(fn () => InsightTypes::keys());
