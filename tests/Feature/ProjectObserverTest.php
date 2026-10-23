<?php

use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\ProjectCache;

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
        ->and(ProjectCache::hasNegative($key))->toBeTrue();

    $project = Project::create(['name' => 'late', 'api_key' => $key]);

    expect(ProjectCache::hasNegative($key))->toBeFalse()
        ->and($resolver->resolve($key))->toBe($project->id);
});

it('forgets the old key and lets the new key resolve when the api key changes', function () {
    $project = Project::factory()->create();
    $old = $project->api_key;
    $new = Project::generateApiKey();
    $resolver = app(ProjectResolver::class);

    expect($resolver->resolve($old))->toBe($project->id)
        ->and($resolver->resolve($new))->toBeNull()
        ->and(ProjectCache::hasPositive($old))->toBeTrue()
        ->and(ProjectCache::hasNegative($new))->toBeTrue();

    $project->update(['api_key' => $new]);

    expect(ProjectCache::hasPositive($old))->toBeFalse()
        ->and(ProjectCache::hasNegative($new))->toBeFalse()
        ->and($resolver->resolve($old))->toBeNull()
        ->and($resolver->resolve($new))->toBe($project->id);
});

it('leaves the cache alone when something other than the api key changes', function () {
    $project = Project::factory()->create();
    app(ProjectResolver::class)->resolve($project->api_key);

    $project->update(['name' => 'Renamed']);

    expect(ProjectCache::hasPositive($project->api_key))->toBeTrue();
});

it('forgets the key when a project is deleted', function () {
    $project = Project::factory()->create();
    $resolver = app(ProjectResolver::class);
    expect($resolver->resolve($project->api_key))->toBe($project->id);

    $project->delete();

    expect(ProjectCache::hasPositive($project->api_key))->toBeFalse()
        ->and($resolver->resolve($project->api_key))->toBeNull();
});

it('does not touch the cache before the transaction commits', function () {
    $project = Project::factory()->create();
    app(ProjectResolver::class)->resolve($project->api_key);

    DB::transaction(function () use ($project) {
        $project->delete();

        // Forgetting now would let a concurrent request re-cache the still-committed row.
        expect(ProjectCache::hasPositive($project->api_key))->toBeTrue();
    });

    expect(ProjectCache::hasPositive($project->api_key))->toBeFalse();
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

    expect(ProjectCache::hasPositive($project->api_key))->toBeTrue()
        ->and(Project::whereKey($project->id)->exists())->toBeTrue();
});

it('does not break a create when the cache is down, and reports it', function () {
    Exceptions::fake();
    ProjectCache::breakStore();

    $project = Project::create(['name' => 'x', 'api_key' => Project::generateApiKey()]);

    expect(Project::whereKey($project->id)->exists())->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'cache store unavailable');
});

it('does not break a key update when the cache is down, and reports it', function () {
    $project = Project::factory()->create();
    $new = Project::generateApiKey();
    Exceptions::fake();
    ProjectCache::breakStore();

    $project->update(['api_key' => $new]);

    expect(Project::whereKey($project->id)->value('api_key'))->toBe($new);
    Exceptions::assertReported(RuntimeException::class);
});

it('does not break a delete when the cache is down, and reports it', function () {
    $project = Project::factory()->create();
    Exceptions::fake();
    ProjectCache::breakStore();

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

it('KNOWN LIMITATION: a query-builder mass delete skips the observer (flip this when it is fixed)', function () {
    $project = Project::factory()->create();
    app(ProjectResolver::class)->resolve($project->api_key);

    // Query-builder deletes bypass model events by design: anything that removes or re-keys
    // projects must go through the model (see project:delete / project:rotate-key).
    Project::where('id', $project->id)->delete();

    expect(ProjectCache::hasPositive($project->api_key))->toBeTrue();
});

it('forgets the old key after commit when the key changes inside a transaction', function () {
    $project = Project::factory()->create();
    $old = $project->api_key;
    $new = Project::generateApiKey();
    $resolver = app(ProjectResolver::class);
    expect($resolver->resolve($old))->toBe($project->id);

    DB::transaction(function () use ($project, $old, $new) {
        $project->update(['api_key' => $new]);

        expect(ProjectCache::hasPositive($old))->toBeTrue();
    });

    expect(ProjectCache::hasPositive($old))->toBeFalse()
        ->and($resolver->resolve($old))->toBeNull()
        ->and($resolver->resolve($new))->toBe($project->id);
});

it('forgets every key when one instance is re-keyed twice inside one transaction (A -> B -> C)', function () {
    $project = Project::factory()->create();
    $a = $project->api_key;
    $b = Project::generateApiKey();
    $c = Project::generateApiKey();
    foreach ([$a, $b, $c] as $key) {
        Cache::put('project_id_by_key:'.$key, $project->id, 300);
    }

    DB::transaction(function () use ($project, $b, $c) {
        $project->update(['api_key' => $b]);
        $project->update(['api_key' => $c]);
    });

    $resolver = app(ProjectResolver::class);
    expect(ProjectCache::hasPositive($a))->toBeFalse()
        ->and(ProjectCache::hasPositive($b))->toBeFalse()
        ->and(ProjectCache::hasPositive($c))->toBeFalse()
        ->and($resolver->resolve($a))->toBeNull()
        ->and($resolver->resolve($b))->toBeNull()
        ->and($resolver->resolve($c))->toBe($project->id);
});

it('forgets nothing when a re-key is rolled back, and the old key still resolves', function () {
    $project = Project::factory()->create();
    $old = $project->api_key;
    $new = Project::generateApiKey();
    $resolver = app(ProjectResolver::class);
    expect($resolver->resolve($old))->toBe($project->id)
        ->and($resolver->resolve($new))->toBeNull();

    try {
        DB::transaction(function () use ($project, $new) {
            $project->update(['api_key' => $new]);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(ProjectCache::hasPositive($old))->toBeTrue()
        ->and(ProjectCache::hasNegative($new))->toBeTrue()
        ->and($resolver->resolve($old))->toBe($project->id)
        ->and($resolver->resolve($new))->toBeNull();
});

it('KNOWN LIMITATION: saveQuietly() skips the observer (flip this when it is fixed)', function () {
    $project = Project::factory()->create();
    $old = $project->api_key;
    app(ProjectResolver::class)->resolve($old);

    $project->api_key = Project::generateApiKey();
    $project->saveQuietly();

    expect(ProjectCache::hasPositive($old))->toBeTrue();
});

it('forgets the old key when other attributes change in the same save', function () {
    $project = Project::factory()->create();
    $old = $project->api_key;
    $new = Project::generateApiKey();
    app(ProjectResolver::class)->resolve($old);

    $project->update(['name' => 'Renamed', 'api_key' => $new, 'timezone' => 'Europe/Istanbul']);

    expect(ProjectCache::hasPositive($old))->toBeFalse();
});

it('leaves the cache alone on touch()', function () {
    $project = Project::factory()->create();
    app(ProjectResolver::class)->resolve($project->api_key);

    $project->touch();

    expect(ProjectCache::hasPositive($project->api_key))->toBeTrue();
});
