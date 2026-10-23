<?php

use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\FailingCacheStore;

function positiveEntry(string $key): bool
{
    return Cache::has('project_id_by_key:'.$key);
}

function negativeEntry(string $key): bool
{
    return Cache::has('project_missing:'.sha1($key));
}

function breakCache(): void
{
    Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
    config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);
}

it('is registered on the project model', function () {
    new Project; // #[ObservedBy] is resolved when the model boots

    expect(Project::getEventDispatcher()->hasListeners('eloquent.created: '.Project::class))->toBeTrue()
        ->and(Project::getEventDispatcher()->hasListeners('eloquent.updated: '.Project::class))->toBeTrue()
        ->and(Project::getEventDispatcher()->hasListeners('eloquent.deleted: '.Project::class))->toBeTrue();
});

it('drops a negative entry when a project is created with a key that was probed before', function () {
    $key = Project::generateApiKey();
    $resolver = app(ProjectResolver::class);

    expect($resolver->resolve($key))->toBeNull()
        ->and(negativeEntry($key))->toBeTrue();

    $project = Project::create(['name' => 'late', 'api_key' => $key]);

    expect(negativeEntry($key))->toBeFalse()
        ->and($resolver->resolve($key))->toBe($project->id);
});

it('forgets the old key and lets the new key resolve when the api key changes', function () {
    $project = Project::factory()->create();
    $old = $project->api_key;
    $new = Project::generateApiKey();
    $resolver = app(ProjectResolver::class);

    expect($resolver->resolve($old))->toBe($project->id)
        ->and($resolver->resolve($new))->toBeNull()
        ->and(positiveEntry($old))->toBeTrue()
        ->and(negativeEntry($new))->toBeTrue();

    $project->update(['api_key' => $new]);

    expect(positiveEntry($old))->toBeFalse()
        ->and(negativeEntry($new))->toBeFalse()
        ->and($resolver->resolve($old))->toBeNull()
        ->and($resolver->resolve($new))->toBe($project->id);
});

it('leaves the cache alone when something other than the api key changes', function () {
    $project = Project::factory()->create();
    app(ProjectResolver::class)->resolve($project->api_key);

    $project->update(['name' => 'Renamed']);

    expect(positiveEntry($project->api_key))->toBeTrue();
});

it('forgets the key when a project is deleted', function () {
    $project = Project::factory()->create();
    $resolver = app(ProjectResolver::class);
    expect($resolver->resolve($project->api_key))->toBe($project->id);

    $project->delete();

    expect(positiveEntry($project->api_key))->toBeFalse()
        ->and($resolver->resolve($project->api_key))->toBeNull();
});

it('does not touch the cache before the transaction commits', function () {
    $project = Project::factory()->create();
    app(ProjectResolver::class)->resolve($project->api_key);

    DB::transaction(function () use ($project) {
        $project->delete();

        // Forgetting now would let a concurrent request re-cache the still-committed row.
        expect(positiveEntry($project->api_key))->toBeTrue();
    });

    expect(positiveEntry($project->api_key))->toBeFalse();
});

it('never forgets when the transaction rolls back', function () {
    $project = Project::factory()->create();
    app(ProjectResolver::class)->resolve($project->api_key);

    try {
        DB::transaction(function () use ($project) {
            $project->delete();

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(positiveEntry($project->api_key))->toBeTrue()
        ->and(Project::whereKey($project->id)->exists())->toBeTrue();
});

it('does not break a create when the cache is down, and reports it', function () {
    Exceptions::fake();
    breakCache();

    $project = Project::create(['name' => 'x', 'api_key' => Project::generateApiKey()]);

    expect(Project::whereKey($project->id)->exists())->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'cache store unavailable');
});

it('does not break a key update when the cache is down, and reports it', function () {
    $project = Project::factory()->create();
    $new = Project::generateApiKey();
    Exceptions::fake();
    breakCache();

    $project->update(['api_key' => $new]);

    expect(Project::whereKey($project->id)->value('api_key'))->toBe($new);
    Exceptions::assertReported(RuntimeException::class);
});

it('does not break a delete when the cache is down, and reports it', function () {
    $project = Project::factory()->create();
    Exceptions::fake();
    breakCache();

    $project->delete();

    expect(Project::whereKey($project->id)->exists())->toBeFalse();
    Exceptions::assertReported(RuntimeException::class);
});

it('still forgets the new key when forgetting the old key fails', function () {
    $project = Project::factory()->create();
    $old = $project->api_key;
    $new = Project::generateApiKey();
    Exceptions::fake();

    Cache::shouldReceive('forget')->with('project_id_by_key:'.$old)->once()->andThrow(new RuntimeException('down'));
    Cache::shouldReceive('forget')->with('project_id_by_key:'.$new)->once()->andReturn(true);
    Cache::shouldReceive('forget')->with('project_missing:'.sha1($new))->once()->andReturn(true);

    $project->update(['api_key' => $new]);

    Exceptions::assertReported(RuntimeException::class);
});

it('does not fire for mass operations that skip model events (known limitation)', function () {
    $project = Project::factory()->create();
    app(ProjectResolver::class)->resolve($project->api_key);

    // Query-builder deletes bypass model events by design: anything that removes or re-keys
    // projects must go through the model (see project:delete / project:rotate-key).
    Project::where('id', $project->id)->delete();

    expect(positiveEntry($project->api_key))->toBeTrue();
});

it('forgets the old key after commit when the key changes inside a transaction', function () {
    $project = Project::factory()->create();
    $old = $project->api_key;
    $new = Project::generateApiKey();
    $resolver = app(ProjectResolver::class);
    expect($resolver->resolve($old))->toBe($project->id);

    DB::transaction(function () use ($project, $old, $new) {
        $project->update(['api_key' => $new]);

        expect(positiveEntry($old))->toBeTrue();
    });

    // After commit the model's "original" attributes already hold the new key.
    expect(positiveEntry($old))->toBeFalse()
        ->and($resolver->resolve($old))->toBeNull()
        ->and($resolver->resolve($new))->toBe($project->id);
});
