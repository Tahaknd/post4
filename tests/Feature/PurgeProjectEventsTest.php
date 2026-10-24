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

it('deletes only the given project with a bound parameter, waits for the mutation and verifies the result', function () {
    Http::fake([
        '*' => Http::sequence()->push('')->push('{"data":[{"n":"0"}]}'),
    ]);

    (new PurgeProjectEvents(990501))->handle(purgeClient());

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    [$delete] = $recorded[0];
    expect($delete->body())->toBe('DELETE FROM posthog.events WHERE project_id = {project_id:UInt32}')
        ->and(purgeQuery($delete)['param_project_id'])->toBe('990501')
        ->and(purgeQuery($delete)['lightweight_deletes_sync'])->toBe('2');

    [$verify] = $recorded[1];
    expect($verify->body())->toContain('SELECT count()')->toContain('WHERE project_id = {project_id:UInt32}')
        ->and(purgeQuery($verify)['param_project_id'])->toBe('990501');
});

it('is idempotent: purging a project with no rows left succeeds', function () {
    Http::fake(['*' => Http::sequence()->push('')->push('{"data":[{"n":"0"}]}')->push('')->push('{"data":[{"n":"0"}]}')]);

    $job = new PurgeProjectEvents(990501);
    $job->handle(purgeClient());
    $job->handle(purgeClient());

    Http::assertSentCount(4);
});

it('fails, so that it is retried, while rows remain after the delete', function () {
    Http::fake(['*' => Http::sequence()->push('')->push('{"data":[{"n":"3"}]}')]);

    expect(fn () => (new PurgeProjectEvents(990501))->handle(purgeClient()))
        ->toThrow(RuntimeException::class, 'left 3 event rows');
});

it('refuses to purge a project that still exists and sends nothing', function () {
    Http::fake();
    $project = Project::factory()->create();
    Log::spy();

    (new PurgeProjectEvents($project->id))->handle(purgeClient());

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

it('refuses ids that are not positive ints', function (int $id) {
    expect(fn () => new PurgeProjectEvents($id))->toThrow(InvalidArgumentException::class);
})->with([0, -1, PHP_INT_MIN]);

it('never interpolates the id into the SQL text', function () {
    Http::fake(['*' => Http::sequence()->push('')->push('{"data":[{"n":"0"}]}')]);

    (new PurgeProjectEvents(990512))->handle(purgeClient());

    foreach (Http::recorded() as [$request]) {
        expect($request->body())->not->toContain('990512');
    }
});

it('surfaces a ClickHouse failure without server text, urls or the password', function () {
    Http::fake(['*' => Http::response("Code: 516. DB::Exception: posthog: Authentication failed: password is incorrect, or there is no user with such name. {$_SERVER['SCRIPT_NAME']} ".PURGE_PASSWORD, 516)]);

    try {
        (new PurgeProjectEvents(990501))->handle(purgeClient());
        $this->fail('expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Purge of project 990501 failed: ClickHouse authentication_failed (code 516).')
            ->and($e->getMessage())->not->toContain(PURGE_PASSWORD)
            ->and($e->getPrevious())->toBeNull();
    }
});

it('reduces a connection failure to its class name', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to ch port 8123 with '.PURGE_PASSWORD));

    try {
        (new PurgeProjectEvents(990501))->handle(purgeClient());
        $this->fail('expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Purge of project 990501 failed: Illuminate\Http\Client\ConnectionException.')
            ->and($e->getMessage())->not->toContain(PURGE_PASSWORD);
    }
});

it('logs the id and exception class, nothing else, when it fails for good', function () {
    Log::spy();

    (new PurgeProjectEvents(990501, pass: 2))->failed(new RuntimeException('Purge of project 990501 failed: x.'));

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context === [
        'project_id' => 990501,
        'pass' => 2,
        'exception' => RuntimeException::class,
    ]);
});

it('is a retry-safe, unique, bounded queue job', function () {
    $job = new PurgeProjectEvents(990501);

    expect($job)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->tries)->toBe(5)
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

it('runs on the default queue that Horizon watches', function () {
    $watched = config('horizon.defaults.supervisor-1.queue');
    $job = new PurgeProjectEvents(1);

    expect($watched)->toContain($job->queue ?? 'default');
});
