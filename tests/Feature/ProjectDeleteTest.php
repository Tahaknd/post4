<?php

use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\FailingCacheStore;

it('deletes a project by key and the key stops resolving even when cached', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $resolver = app(ProjectResolver::class);
    expect($resolver->resolve($project->api_key))->toBe($project->id);

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key]))->toBe(0);

    expect(Artisan::output())->toContain("Project #{$project->id} deleted.")->toContain('ClickHouse')
        ->and(Project::whereKey($project->id)->exists())->toBeFalse()
        ->and(Project::whereKey($other->id)->exists())->toBeTrue()
        ->and($resolver->resolve($project->api_key))->toBeNull();
});

it('accepts --force and stays non-interactive', function () {
    $project = Project::factory()->create();

    $this->artisan('project:delete', ['api_key' => $project->api_key, '--force' => true])->assertSuccessful();

    expect(Project::count())->toBe(0);
});

it('rejects a malformed key without touching the database', function (string $key) {
    Project::factory()->create();
    DB::enableQueryLog();

    expect(Artisan::call('project:delete', ['api_key' => $key]))->toBe(1);

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

it('fails for an unknown key and deletes nothing', function () {
    $project = Project::factory()->create();

    expect(Artisan::call('project:delete', ['api_key' => Project::generateApiKey()]))->toBe(1);

    expect(Artisan::output())->toContain('Project not found')
        ->and(Project::whereKey($project->id)->exists())->toBeTrue();
});

it('still deletes and exits 0 with a warning when the cache is down', function () {
    $project = Project::factory()->create();
    Exceptions::fake();
    Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
    config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);

    expect(Artisan::call('project:delete', ['api_key' => $project->api_key]))->toBe(0);

    expect(Artisan::output())->toContain("Project #{$project->id} deleted.")
        ->toContain((string) ProjectResolver::POSITIVE_TTL_SECONDS)
        ->and(Project::whereKey($project->id)->exists())->toBeFalse();
    Exceptions::assertReported(RuntimeException::class);
});
