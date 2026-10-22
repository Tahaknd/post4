<?php

use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Support\Facades\Artisan;

it('rotates the key so the old one stops resolving even when cached', function () {
    $project = Project::factory()->create();
    $old = $project->api_key;
    $resolver = app(ProjectResolver::class);

    // Warm the positive cache with the old key.
    expect($resolver->resolve($old))->toBe($project->id);

    $this->artisan('project:rotate-key', ['id' => $project->id])->assertSuccessful();

    $new = $project->fresh()->api_key;
    expect($new)->not->toBe($old)
        ->and($new)->toMatch('/^phc_[A-Za-z0-9]{43}$/')
        ->and($resolver->resolve($old))->toBeNull()
        ->and($resolver->resolve($new))->toBe($project->id);
});

it('prints only the new key with --key-only', function () {
    $project = Project::factory()->create();

    expect(Artisan::call('project:rotate-key', ['id' => $project->id, '--key-only' => true]))->toBe(0);
    expect(trim(Artisan::output()))->toBe($project->fresh()->api_key);
});

it('mentions the new key in the normal output', function () {
    $project = Project::factory()->create();

    expect(Artisan::call('project:rotate-key', ['id' => $project->id]))->toBe(0);

    expect(Artisan::output())->toContain($project->fresh()->api_key);
});

it('fails for an unknown project id', function () {
    $this->artisan('project:rotate-key', ['id' => 999999])->assertFailed();
});
