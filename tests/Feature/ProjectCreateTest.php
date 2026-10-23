<?php

use App\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\ProjectCache;

it('creates a project with a phc_ api key', function () {
    $this->artisan('project:create', ['name' => 'Demo', '--key-only' => true])->assertSuccessful();

    $project = Project::firstOrFail();
    expect($project->name)->toBe('Demo')
        ->and($project->api_key)->toStartWith('phc_')
        ->and(strlen($project->api_key))->toBe(47);
});

it('generates api keys matching the phc_ format', function () {
    expect(Project::generateApiKey())->toMatch('/^phc_[A-Za-z0-9]{43}$/');
});

it('gives two created projects different api keys', function () {
    $this->artisan('project:create', ['name' => 'One', '--key-only' => true])->assertSuccessful();
    $this->artisan('project:create', ['name' => 'Two', '--key-only' => true])->assertSuccessful();

    $keys = Project::pluck('api_key');

    expect($keys)->toHaveCount(2)->and($keys->unique())->toHaveCount(2);
});

it('creates a project with a valid key from the factory', function () {
    $project = Project::factory()->create();

    expect($project->exists)->toBeTrue()
        ->and($project->name)->not->toBe('')
        ->and($project->api_key)->toMatch('/^phc_[A-Za-z0-9]{43}$/')
        ->and($project->timezone)->toBe('UTC');
});

it('prints exactly one json line with --json', function () {
    expect(Artisan::call('project:create', ['name' => 'Demo', '--json' => true]))->toBe(0);

    $output = Artisan::output();
    $project = Project::firstOrFail();

    expect($output)->toBe(json_encode(['id' => $project->id, 'api_key' => $project->api_key])."\n")
        ->and($output)->toMatch('/^\{"id":\d+,"api_key":"phc_[A-Za-z0-9]{43}"\}\n\z/')
        ->and(json_decode($output, true))->toBe(['id' => $project->id, 'api_key' => $project->api_key]);
});

it('rejects --json together with --key-only without creating anything', function () {
    expect(Artisan::call('project:create', ['name' => 'Demo', '--json' => true, '--key-only' => true]))->toBe(2)
        ->and(Artisan::output())->toContain('--json')->toContain('--key-only')
        ->and(Project::count())->toBe(0);
});

it('keeps the default and --key-only outputs unchanged', function () {
    expect(Artisan::call('project:create', ['name' => 'A']))->toBe(0);
    $project = Project::firstOrFail();
    expect(Artisan::output())->toBe("Proje #{$project->id} oluşturuldu. API anahtarı: {$project->api_key}\n");

    expect(Artisan::call('project:create', ['name' => 'B', '--key-only' => true]))->toBe(0);
    expect(Artisan::output())->toBe(Project::where('name', 'B')->value('api_key')."\n");
});

it('still prints exactly one json line with --json when the cache is down', function () {
    Exceptions::fake();
    ProjectCache::breakStore();

    expect(Artisan::call('project:create', ['name' => 'Demo', '--json' => true]))->toBe(0);

    $output = Artisan::output();
    $project = Project::firstOrFail();

    expect($output)->toBe(json_encode(['id' => $project->id, 'api_key' => $project->api_key])."\n");
    Exceptions::assertReported(RuntimeException::class);
});
