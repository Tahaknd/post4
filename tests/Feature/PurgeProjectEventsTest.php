<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Jobs\PurgeProjectEvents;
use App\Models\Project;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const PURGE_PASSWORD = 'admin-secret-password';

beforeEach(function () {
    // No real waiting: no polling unless a test asks for it.
    PurgeProjectEvents::$budgetSeconds = 0;
    PurgeProjectEvents::$pollMicroseconds = 0;
});

afterEach(function () {
    PurgeProjectEvents::$budgetSeconds = 50;
    PurgeProjectEvents::$pollMicroseconds = 1_000_000;
});

function purgeClient(): ClickHouseClient
{
    return new ClickHouseClient('http://ch:8123', 'posthog', 'posthog', PURGE_PASSWORD);
}

/** @return array<string, mixed> */
function purgeQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

/**
 * Fakes ClickHouse by what the job asks: count() answers from $counts in order (the last value repeats),
 * system.mutations answers with $pending / $failing, a DELETE answers empty.
 *
 * @param  list<int>  $counts
 */
function fakeClickHouse(array $counts, int $pending = 0, int $failing = 0): void
{
    $last = 0;
    Http::fake(function (Request $request) use (&$counts, &$last, $pending, $failing) {
        $body = $request->body();

        return match (true) {
            str_contains($body, 'system.mutations') => Http::response(json_encode(['data' => [['pending' => (string) $pending, 'failing' => (string) $failing]]])),
            str_contains($body, 'SELECT count() AS n') => Http::response(json_encode(['data' => [['n' => (string) ($last = array_shift($counts) ?? $last)]]])),
            str_starts_with($body, 'DELETE FROM') => Http::response(''),
            default => Http::response('unexpected query', 500),
        };
    });
}

/** @return list<Request> every DELETE the job sent */
function sentDeletes(): array
{
    return array_values(array_filter(
        array_map(fn (array $pair) => $pair[0], Http::recorded()->all()),
        fn (Request $request) => str_starts_with($request->body(), 'DELETE FROM'),
    ));
}

/** A job wired to a fake queue job, so release() and fail() can be asserted. */
function purgeJob(int $project = 990501, int $pass = 1): PurgeProjectEvents
{
    return (new PurgeProjectEvents($project, $pass))->withFakeQueueInteractions();
}

it('does nothing when the project has no rows left', function () {
    fakeClickHouse([0]);
    $job = purgeJob();

    $job->handle(purgeClient());

    expect(sentDeletes())->toBe([]);
    $job->assertNotReleased()->assertNotFailed();
    Http::assertSentCount(1); // the count only: no mutation lookup, no DELETE
});

it('issues one asynchronous DELETE with a bound parameter when rows remain and no mutation is pending', function () {
    PurgeProjectEvents::$budgetSeconds = 100; // allow polling
    fakeClickHouse([5, 5, 0]);
    $job = purgeJob();

    $job->handle(purgeClient());

    $deletes = sentDeletes();
    expect($deletes)->toHaveCount(1)
        ->and($deletes[0]->body())->toBe('DELETE FROM posthog.events WHERE project_id = {project_id:UInt32}')
        ->and(purgeQuery($deletes[0])['param_project_id'])->toBe('990501')
        ->and(purgeQuery($deletes[0])['lightweight_deletes_sync'])->toBe('0');
    $job->assertNotReleased()->assertNotFailed();
});

it('never stacks a second DELETE while a mutation for this project is still pending', function () {
    fakeClickHouse([5], pending: 1);
    $job = purgeJob();

    $job->handle(purgeClient());

    expect(sentDeletes())->toBe([]);
    $job->assertReleased(PurgeProjectEvents::RELEASE_DELAY_SECONDS);
});

it('looks for the pending mutation by the project id as a whole number, in system.mutations', function () {
    fakeClickHouse([5], pending: 0);

    purgeJob(990501)->handle(purgeClient());

    $lookup = array_values(array_filter(
        array_map(fn (array $pair) => $pair[0], Http::recorded()->all()),
        fn (Request $request) => str_contains($request->body(), 'system.mutations'),
    ));
    expect($lookup)->toHaveCount(1)
        ->and($lookup[0]->body())->toContain("table = 'events'")->toContain('is_done = 0')
        ->and($lookup[0]->body())->not->toContain('990501')
        ->and(purgeQuery($lookup[0])['param_pattern'])->toBe('(^|[^0-9])990501([^0-9]|$)')
        ->and(preg_match('/'.purgeQuery($lookup[0])['param_pattern'].'/', "(UPDATE _row_exists = 0 WHERE project_id = _CAST(990501, 'UInt32'))"))->toBe(1)
        ->and(preg_match('/'.purgeQuery($lookup[0])['param_pattern'].'/', "(UPDATE _row_exists = 0 WHERE project_id = _CAST(9905011, 'UInt32'))"))->toBe(0);
});

it('releases itself instead of failing while the mutation has not finished', function () {
    fakeClickHouse([5]); // rows stay, no pending mutation: it issues the DELETE, the budget is spent, so it releases
    $job = purgeJob();

    $job->handle(purgeClient());

    expect(sentDeletes())->toHaveCount(1);
    $job->assertReleased(PurgeProjectEvents::RELEASE_DELAY_SECONDS)->assertNotFailed();
});

it('warns when the pending mutation keeps failing, without any server text', function () {
    Log::spy();
    fakeClickHouse([5], pending: 1, failing: 1);

    purgeJob()->handle(purgeClient());

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context === ['project_id' => 990501, 'pass' => 1]);
});

it('is idempotent: a project with no rows is purged repeatedly without a DELETE', function () {
    fakeClickHouse([0, 0]);

    purgeJob()->handle(purgeClient());
    purgeJob(990501, 2)->handle(purgeClient());

    expect(sentDeletes())->toBe([]);
});

it('refuses to purge a project that still exists and sends nothing', function () {
    Http::fake();
    $project = Project::factory()->create();
    Log::spy();

    purgeJob($project->id)->handle(purgeClient());

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

it('refuses ids that are not positive ints', function (int $id) {
    expect(fn () => new PurgeProjectEvents($id))->toThrow(InvalidArgumentException::class);
})->with([0, -1, PHP_INT_MIN]);

it('never interpolates the id into the SQL text', function () {
    fakeClickHouse([5, 0], pending: 0);
    PurgeProjectEvents::$budgetSeconds = 100;

    purgeJob(990512)->handle(purgeClient());

    foreach (Http::recorded() as [$request]) {
        expect($request->body())->not->toContain('990512');
    }
});

it('fails at once, without retrying, for a non-transient ClickHouse error', function (int $code, string $kind) {
    Http::fake(['*' => Http::response("Code: {$code}. DB::Exception: secret text ".PURGE_PASSWORD, 500)]);
    $job = purgeJob();

    $job->handle(purgeClient());

    $job->assertFailed();
    expect(sentDeletes())->toBe([]);
})->with([[516, 'authentication_failed'], [452, 'constraint_violation']]);

it('reports the failure without server text, urls or the password', function () {
    Http::fake(['*' => Http::response('Code: 516. DB::Exception: '.PURGE_PASSWORD, 500)]);
    $job = purgeJob();

    $job->handle(purgeClient());

    $job->assertFailed();
    $reason = (new ReflectionProperty($job->job, 'failedWith'))->getValue($job->job);
    expect($reason->getMessage())->toBe('Purge of project 990501 (events) failed: ClickHouse authentication_failed (code 516).')
        ->and($reason->getMessage())->not->toContain(PURGE_PASSWORD)
        ->and($reason->getPrevious())->toBeNull();
});

it('throws a sanitised exception for a transient ClickHouse error so the queue retries it', function () {
    Http::fake(['*' => Http::response('Code: 202. DB::Exception: busy '.PURGE_PASSWORD, 500)]);

    try {
        purgeJob()->handle(purgeClient());
        $this->fail('expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Purge of project 990501 (events) failed: ClickHouse busy (code 202).')
            ->and($e->getMessage())->not->toContain(PURGE_PASSWORD);
    }
});

it('reduces a connection failure to its class name', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to ch port 8123 with '.PURGE_PASSWORD));

    try {
        purgeJob()->handle(purgeClient());
        $this->fail('expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Purge of project 990501 (events) failed: '.ConnectionException::class.'.')
            ->and($e->getMessage())->not->toContain(PURGE_PASSWORD);
    }
});

it('logs the id and exception class, nothing else, when it fails for good', function () {
    Log::spy();

    (new PurgeProjectEvents(990501, pass: 2))->failed(new RuntimeException('Purge of project 990501 failed: x.'));

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context === [
        'table' => 'events',
        'project_id' => 990501,
        'pass' => 2,
        'exception' => RuntimeException::class,
    ]);
});

it('is a bounded, unique job on the maintenance queue that waits by releasing', function () {
    $job = new PurgeProjectEvents(990501);

    expect($job)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe('maintenance')
        ->and($job->tries)->toBe(30)
        ->and($job->maxExceptions)->toBe(5)
        ->and($job->backoff())->toBe([10, 30, 120, 300])
        // Under the Redis queue's retry_after (90 s) so a running purge is never handed to a second worker.
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($job->uniqueFor)->toBeGreaterThan(0);
});

it('keeps one unique lock per project and pass, so the delayed pass is not swallowed by the first', function () {
    expect((new PurgeProjectEvents(5, 1))->uniqueId())->not->toBe((new PurgeProjectEvents(5, 2))->uniqueId())
        ->and((new PurgeProjectEvents(5, 1))->uniqueId())->not->toBe((new PurgeProjectEvents(6, 1))->uniqueId());

    // The lock the dispatcher takes before pushing: a second dispatch of the same pass is refused, the other pass is not.
    $lock = new UniqueLock(Cache::driver());
    expect($lock->acquire(new PurgeProjectEvents(5, 1)))->toBeTrue()
        ->and($lock->acquire(new PurgeProjectEvents(5, 1)))->toBeFalse()
        ->and($lock->acquire(new PurgeProjectEvents(5, 2)))->toBeTrue()
        ->and($lock->acquire(new PurgeProjectEvents(6, 1)))->toBeTrue();
});

it('has a Horizon supervisor for the maintenance queue in every environment, under the retry_after', function () {
    $horizon = config('horizon');

    expect($horizon['defaults']['supervisor-maintenance']['queue'])->toBe(['maintenance'])
        ->and($horizon['defaults']['supervisor-maintenance']['timeout'])->toBeLessThan((int) config('queue.connections.redis.retry_after'));

    foreach ($horizon['environments'] as $environment => $supervisors) {
        expect($supervisors)->toHaveKey('supervisor-maintenance', ['maxProcesses' => 1]);
    }
});
