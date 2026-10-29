<?php

use App\Actions\Projects\QueueEventsPurge;
use App\Console\Commands\ProjectDelete;
use App\Domain\Capture\ProjectResolver;
use App\Jobs\PurgeProjectEvents;
use App\Jobs\PurgeProjectPersonDistinctIds;
use App\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProjectCache;

// The default delete queues the events purge: nothing here may reach a real queue or ClickHouse.
beforeEach(fn () => Queue::fake());

function deletePrompt(Project $project, bool $purge = true): string
{
    return "Delete project #{$project->id} \"{$project->name}\"? ".($purge ? 'Its events will be deleted from ClickHouse too.' : 'Its events remain in ClickHouse.');
}

/** The console wraps long lines: compare without line breaks. */
function flattened(string $output): string
{
    return preg_replace('/\s+/', ' ', $output);
}

it('deletes a project by key and the key stops resolving even when cached', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $resolver = app(ProjectResolver::class);
    expect($resolver->resolve($project->api_key))->toBe($project->id);

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key, '--force' => true]))->toBe(0);

    expect(Artisan::output())->toContain("Project #{$project->id} deleted.")
        ->and(Project::whereKey($project->id)->exists())->toBeFalse()
        ->and(Project::whereKey($other->id)->exists())->toBeTrue()
        ->and($resolver->resolve($project->api_key))->toBeNull();
});

it('purges the events by default: both passes go to the maintenance queue', function () {
    $project = Project::factory()->create();

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key, '--force' => true]))->toBe(0);

    expect(flattened(Artisan::output()))->toContain('Events purge queued')->not->toContain('KEPT');
    Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === $project->id && $job->pass === 1 && $job->queue === 'maintenance');
    Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === $project->id && $job->pass === 2 && $job->delay === QueueEventsPurge::SECOND_PASS_DELAY_SECONDS);
});

it('purges the distinct id mapping of the project too: its own job, both passes, same queue', function () {
    $project = Project::factory()->create();

    Artisan::call('project:delete', ['api_key' => $project->api_key, '--force' => true]);

    Queue::assertPushed(PurgeProjectPersonDistinctIds::class, 2);
    Queue::assertPushed(PurgeProjectPersonDistinctIds::class, fn (PurgeProjectPersonDistinctIds $job) => $job->projectId === $project->id && $job->pass === 1 && $job->queue === 'maintenance' && $job->delay === null);
    Queue::assertPushed(PurgeProjectPersonDistinctIds::class, fn (PurgeProjectPersonDistinctIds $job) => $job->projectId === $project->id && $job->pass === 2 && $job->delay === QueueEventsPurge::SECOND_PASS_DELAY_SECONDS);
    Queue::assertPushed(PurgeProjectEvents::class, 2);
});

it('keeps the events with --keep-events, queues no purge and names the follow-up command', function () {
    $project = Project::factory()->create();

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key, '--force' => true, '--keep-events' => true]))->toBe(0);

    expect(flattened(Artisan::output()))->toContain("Events of project #{$project->id} were KEPT in ClickHouse")
        ->toContain("project:purge-events {$project->id}");
    Queue::assertNothingPushed();
});

it('exits 4 when the purge cannot be queued, while the project stays deleted', function () {
    $project = Project::factory()->create();
    config(['queue.default' => 'broken', 'queue.connections.broken' => ['driver' => 'redis', 'connection' => 'unreachable']]);
    config(['database.redis.unreachable' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2]]);
    app()->forgetInstance('queue');
    Queue::swap(app('queue'));

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key, '--force' => true]))->toBe(ProjectDelete::PURGE_NOT_QUEUED);

    expect(ProjectDelete::PURGE_NOT_QUEUED)->toBe(4)
        ->and(flattened(Artisan::output()))->toContain('could not be queued')->toContain("project:purge-events {$project->id}")
        ->and(Project::whereKey($project->id)->exists())->toBeFalse();
});

it('asks for confirmation on an interactive run and deletes nothing when it is declined', function () {
    $project = Project::factory()->create();

    $this->artisan('project:delete', ['api_key' => $project->api_key])
        ->expectsConfirmation(deletePrompt($project), 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(1);

    expect(Project::whereKey($project->id)->exists())->toBeTrue();
});

it('words the confirmation prompt after what will happen to the events', function () {
    $project = Project::factory()->create(['name' => 'Shop']);

    $this->artisan('project:delete', ['api_key' => $project->api_key, '--keep-events' => true])
        ->expectsConfirmation(deletePrompt($project, purge: false), 'no')
        ->assertExitCode(1);

    expect(Project::whereKey($project->id)->exists())->toBeTrue();
});

it('deletes after the interactive confirmation is accepted', function () {
    $project = Project::factory()->create();

    $this->artisan('project:delete', ['api_key' => $project->api_key])
        ->expectsConfirmation(deletePrompt($project), 'yes')
        ->assertExitCode(0);

    expect(Project::whereKey($project->id)->exists())->toBeFalse();
});

it('does not prompt with --force', function () {
    $project = Project::factory()->create();

    // An unexpected question would fail the test.
    $this->artisan('project:delete', ['api_key' => $project->api_key, '--force' => true])->assertExitCode(0);

    expect(Project::count())->toBe(0);
});

it('never prompts on a non-interactive run', function () {
    $project = Project::factory()->create();

    $this->artisan('project:delete', ['api_key' => $project->api_key, '--no-interaction' => true])->assertExitCode(0);

    expect(Project::count())->toBe(0);
});

it('does not prompt for a key that is malformed or unknown', function () {
    $this->artisan('project:delete', ['api_key' => 'bad'])->assertExitCode(2);
    $this->artisan('project:delete', ['api_key' => Project::generateApiKey()])->assertExitCode(3);
});

it('exits 2 for a malformed key without touching the database', function (string $key) {
    Project::factory()->create();
    DB::enableQueryLog();

    expect(Artisan::call('project:delete', ['api_key' => $key, '--force' => true]))->toBe(2);

    expect(Artisan::output())->toContain('Invalid API key format')
        ->and(DB::getQueryLog())->toBeEmpty()
        ->and(Project::count())->toBe(1);
})->with([
    'empty' => '',
    'short' => 'phc_short',
    'wrong prefix' => 'phx_'.str_repeat('a', 43),
    'too long' => 'phc_'.str_repeat('a', 44),
    'bad chars' => 'phc_'.str_repeat('a', 42).'-',
    'trailing newline' => 'phc_'.str_repeat('a', 43)."\n",
]);

it('exits 3 for an unknown key and deletes nothing', function () {
    $project = Project::factory()->create();

    expect(Artisan::call('project:delete', ['api_key' => Project::generateApiKey(), '--force' => true]))->toBe(ProjectDelete::NOT_FOUND);

    expect(ProjectDelete::NOT_FOUND)->toBe(3)
        ->and(Artisan::output())->toContain('Project not found')
        ->and(Project::whereKey($project->id)->exists())->toBeTrue();
});

it('documents the exit codes and the purge default in its description and help', function () {
    $command = Artisan::all()['project:delete'];

    expect($command->getDescription())->toContain('3 unknown key')->toContain('4 deleted but the events purge could not be queued')
        ->and($command->getHelp())->toContain('3  no project has this API key')
        ->and($command->getHelp())->toContain('2  the API key has an invalid format')
        ->and($command->getHelp())->toContain('4  the project WAS deleted')
        ->and($command->getHelp())->toContain('--keep-events')
        ->and($command->getHelp())->toContain('differs from the original plan');
});

it('no longer has the opt-in --purge-events flag', function () {
    expect(Artisan::all()['project:delete']->getDefinition()->hasOption('purge-events'))->toBeFalse();
});

it('still deletes and exits 0 with a warning when the cache is down', function () {
    $project = Project::factory()->create();
    Exceptions::fake();
    ProjectCache::breakStore();

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key, '--force' => true, '--keep-events' => true]))->toBe(0);

    expect(flattened(Artisan::output()))->toContain("Project #{$project->id} deleted.")
        ->toContain((string) ProjectResolver::POSITIVE_TTL_SECONDS)
        ->and(Project::whereKey($project->id)->exists())->toBeFalse();
    Exceptions::assertReported(RuntimeException::class);
});

it('exits 4 when the cache is down, because queueing a unique job needs its lock; the project stays deleted', function () {
    $project = Project::factory()->create();
    Exceptions::fake();
    ProjectCache::breakStore();

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key, '--force' => true]))->toBe(4);

    expect(Project::whereKey($project->id)->exists())->toBeFalse();
});

describe('project:purge-events', function () {
    it('queues both passes for a deleted project id', function () {
        expect(Artisan::call('project:purge-events', ['id' => 990501, '--force' => true]))->toBe(0);

        expect(flattened(Artisan::output()))->toContain('Events purge queued for project #990501');
        Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === 990501 && $job->pass === 1);
        Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === 990501 && $job->pass === 2);
    });

    it('refuses a project that still exists', function () {
        $project = Project::factory()->create();

        expect(Artisan::call('project:purge-events', ['id' => $project->id, '--force' => true]))->toBe(1);

        expect(flattened(Artisan::output()))->toContain('still exists');
        Queue::assertNothingPushed();
    });

    it('rejects an invalid id with exit code 2', function (string $id) {
        expect(Artisan::call('project:purge-events', ['id' => $id, '--force' => true]))->toBe(2);
        Queue::assertNothingPushed();
    })->with(['letters' => 'abc', 'zero' => '0', 'negative' => '-1', 'huge' => '99999999999999999999']);

    it('asks for confirmation on an interactive run', function () {
        $this->artisan('project:purge-events', ['id' => 990501])
            ->expectsConfirmation('Delete every ClickHouse event of the deleted project #990501?', 'no')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    });
});
