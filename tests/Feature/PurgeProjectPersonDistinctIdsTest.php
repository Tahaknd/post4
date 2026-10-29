<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Jobs\PurgeProjectEvents;
use App\Jobs\PurgeProjectPersonDistinctIds;
use App\Jobs\PurgeProjectRows;
use App\Models\Project;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * The purge of person_distinct_ids is the same job as the purge of events for another table (PurgeProjectRows):
 * PurgeProjectEventsTest covers the shared behaviour through the events job, this file pins what differs.
 */

beforeEach(function () {
    PurgeProjectEvents::$budgetSeconds = 0;
    PurgeProjectEvents::$pollMicroseconds = 0;
});

afterEach(function () {
    PurgeProjectEvents::$budgetSeconds = 50;
    PurgeProjectEvents::$pollMicroseconds = 1_000_000;
});

/** @return list<Request> */
function personSent(): array
{
    return array_map(fn (array $pair) => $pair[0], Http::recorded()->all());
}

function fakePersonClickHouse(array $counts): void
{
    $last = 0;
    Http::fake(function (Request $request) use (&$counts, &$last) {
        $body = $request->body();

        return match (true) {
            str_contains($body, 'system.mutations') => Http::response(json_encode(['data' => [['pending' => '0', 'failing' => '0']]])),
            str_contains($body, 'SELECT count() AS n') => Http::response(json_encode(['data' => [['n' => (string) ($last = array_shift($counts) ?? $last)]]])),
            str_starts_with($body, 'DELETE FROM') => Http::response(''),
            default => Http::response('unexpected query', 500),
        };
    });
}

it('shares one implementation with the events purge and shares nothing else with it', function () {
    $job = new PurgeProjectPersonDistinctIds(990501);

    expect($job)->toBeInstanceOf(PurgeProjectRows::class)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeUnique::class)
        ->and(new PurgeProjectEvents(990501))->toBeInstanceOf(PurgeProjectRows::class)
        ->and($job->queue)->toBe('maintenance')
        ->and($job->tries)->toBe(30)
        ->and($job->maxExceptions)->toBe(5)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});

it('counts, looks for mutations of and deletes from posthog.person_distinct_ids only', function () {
    PurgeProjectEvents::$budgetSeconds = 100;
    fakePersonClickHouse([4, 4, 0]);
    $job = (new PurgeProjectPersonDistinctIds(990502, 1))->withFakeQueueInteractions();

    $job->handle(new ClickHouseClient('http://ch:8123', 'posthog', 'posthog', 'secret'));

    $bodies = array_map(fn (Request $r) => $r->body(), personSent());
    $deletes = array_values(array_filter($bodies, fn (string $b) => str_starts_with($b, 'DELETE FROM')));
    $counts = array_values(array_filter($bodies, fn (string $b) => str_contains($b, 'SELECT count() AS n')));
    $mutations = array_values(array_filter($bodies, fn (string $b) => str_contains($b, 'system.mutations')));

    expect($deletes)->toBe(['DELETE FROM posthog.person_distinct_ids WHERE project_id = {project_id:UInt32}'])
        ->and($counts)->each->toContain('FROM posthog.person_distinct_ids WHERE project_id = {project_id:UInt32}')
        ->and($mutations)->toHaveCount(1)
        ->and($mutations[0])->toContain("table = 'person_distinct_ids'")->not->toContain("table = 'events'")
        ->and(implode("\n", $bodies))->not->toContain('posthog.events');
    $job->assertNotReleased()->assertNotFailed();
});

it('does nothing when the project has no mapping rows left', function () {
    fakePersonClickHouse([0]);
    $job = (new PurgeProjectPersonDistinctIds(990502))->withFakeQueueInteractions();

    $job->handle(new ClickHouseClient('http://ch:8123', 'posthog', 'posthog', 'secret'));

    $job->assertNotReleased()->assertNotFailed();
    Http::assertSentCount(1);
});

it('refuses a project that still exists and sends nothing', function () {
    $project = Project::factory()->create();
    Http::fake();
    Log::spy();

    (new PurgeProjectPersonDistinctIds($project->id))->withFakeQueueInteractions()->handle(new ClickHouseClient('http://ch:8123', 'posthog', 'posthog', 'secret'));

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

it('names the table in the failure, never the server text or the password', function () {
    Http::fake(['*' => Http::response('Code: 516. DB::Exception: password secret rejected', 401)]);
    $job = (new PurgeProjectPersonDistinctIds(990502))->withFakeQueueInteractions();

    $job->handle(new ClickHouseClient('http://ch:8123', 'posthog', 'posthog', 'secret'));

    $job->assertFailed();
    $reason = (new ReflectionProperty($job->job, 'failedWith'))->getValue($job->job);
    expect($reason->getMessage())->toBe('Purge of project 990502 (person_distinct_ids) failed: ClickHouse authentication_failed (code 516).')
        ->and($reason->getMessage())->not->toContain('secret');
});

it('keeps separate unique locks for the two tables, passes and projects', function () {
    expect((new PurgeProjectPersonDistinctIds(5, 1))->uniqueId())->toBe('purge-project-person-distinct-ids:5:1')
        ->and((new PurgeProjectEvents(5, 1))->uniqueId())->toBe('purge-project-events:5:1');

    // a pending events purge must not swallow the person purge of the same project and pass
    $lock = new UniqueLock(Cache::driver());
    expect($lock->acquire(new PurgeProjectEvents(5, 1)))->toBeTrue()
        ->and($lock->acquire(new PurgeProjectPersonDistinctIds(5, 1)))->toBeTrue()
        ->and($lock->acquire(new PurgeProjectPersonDistinctIds(5, 1)))->toBeFalse()
        ->and($lock->acquire(new PurgeProjectPersonDistinctIds(5, 2)))->toBeTrue();
});

it('logs the table next to the id when it fails for good', function () {
    Log::spy();

    (new PurgeProjectPersonDistinctIds(990502, pass: 2))->failed(new RuntimeException('x'));

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context === [
        'table' => 'person_distinct_ids',
        'project_id' => 990502,
        'pass' => 2,
        'exception' => RuntimeException::class,
    ]);
});
