<?php

use App\Models\Project;

it('creates a project with a phc_ api key', function () {
    $this->artisan('project:create', ['name' => 'Demo', '--key-only' => true])->assertSuccessful();

    $project = Project::firstOrFail();
    expect($project->name)->toBe('Demo')
        ->and($project->api_key)->toStartWith('phc_')
        ->and(strlen($project->api_key))->toBe(47);
});
