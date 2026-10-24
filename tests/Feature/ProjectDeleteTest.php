<?php

use App\Actions\Projects\DeleteProject;
use App\Console\Commands\ProjectDelete;
use App\Domain\Capture\ProjectResolver;
use App\Jobs\PurgeProjectEvents;
use App\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProjectCache;

function deletePrompt(Project $project): string
{
    return "Delete project #{$project->id} \"{$project->name}\"? Its events remain in ClickHouse.";
}

it('deletes a project by key and the key stops resolving even when cached', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $resolver = app(ProjectResolver::class);
    expect($resolver->resolve($project->api_key))->toBe($project->id);

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key, '--force' => true]))->toBe(0);

    expect(Artisan::output())->toContain("Project #{$project->id} deleted.")->toContain('ClickHouse')
        ->and(Project::whereKey($project->id)->exists())->toBeFalse()
        ->and(Project::whereKey($other->id)->exists())->toBeTrue()
        ->and($resolver->resolve($project->api_key))->toBeNull();
});

it('asks for confirmation on an interactive run and deletes nothing when it is declined', function () {
    $project = Project::factory()->create();

    $this->artisan('project:delete', ['api_key' => $project->api_key])
        ->expectsConfirmation(deletePrompt($project), 'no')
        ->expectsOutputToContain('Aborted.')
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

it('documents the exit codes in its description and help', function () {
    $command = Artisan::all()['project:delete'];

    expect($command->getDescription())->toContain('3 unknown key')
        ->and($command->getHelp())->toContain('3  no project has this API key')
        ->and($command->getHelp())->toContain('2  the API key has an invalid format');
});

it('still deletes and exits 0 with a warning when the cache is down', function () {
    $project = Project::factory()->create();
    Exceptions::fake();
    ProjectCache::breakStore();

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key, '--force' => true]))->toBe(0);

    expect(Artisan::output())->toContain("Project #{$project->id} deleted.")
        ->toContain((string) ProjectResolver::POSITIVE_TTL_SECONDS)
        ->and(Project::whereKey($project->id)->exists())->toBeFalse();
    Exceptions::assertReported(RuntimeException::class);
});

it('keeps the events by default, queues no purge and warns loudly on stderr', function () {
    Queue::fake();
    $project = Project::factory()->create();

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key, '--force' => true]))->toBe(0);

    $output = preg_replace('/\s+/', ' ', Artisan::output());
    expect($output)->toContain("Events of project #{$project->id} were KEPT in ClickHouse")->toContain('--purge-events');
    Queue::assertNothingPushed();
});

it('queues the two purge passes with --purge-events and says so', function () {
    Queue::fake();
    $project = Project::factory()->create();

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key, '--force' => true, '--purge-events' => true]))->toBe(0);

    expect(Artisan::output())->toContain('Events purge queued')->not->toContain('KEPT');
    Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === $project->id && $job->pass === 1);
    Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === $project->id && $job->pass === 2 && $job->delay === DeleteProject::SECOND_PASS_DELAY_SECONDS);
});

it('words the confirmation prompt after what will happen to the events', function () {
    $project = Project::factory()->create(['name' => 'Shop']);

    $this->artisan('project:delete', ['api_key' => $project->api_key, '--purge-events' => true])
        ->expectsConfirmation("Delete project #{$project->id} \"Shop\"? Its events will be deleted from ClickHouse too.", 'no')
        ->assertExitCode(1);

    expect(Project::whereKey($project->id)->exists())->toBeTrue();
});
