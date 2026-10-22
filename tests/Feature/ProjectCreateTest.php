<?php

use App\Models\Project;

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
