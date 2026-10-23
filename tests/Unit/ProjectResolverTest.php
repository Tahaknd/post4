<?php

use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('resolves a known key to the project id', function () {
    $project = Project::factory()->create();

    expect(app(ProjectResolver::class)->resolve($project->api_key))->toBe($project->id);
});

it('returns null for malformed keys without touching cache or database', function (string $key) {
    DB::enableQueryLog();
    Cache::spy();

    expect(app(ProjectResolver::class)->resolve($key))->toBeNull()
        ->and(DB::getQueryLog())->toBeEmpty();

    Cache::shouldNotHaveReceived('get');
    Cache::shouldNotHaveReceived('remember');
    Cache::shouldNotHaveReceived('put');
})->with([
    'empty' => '',
    'short' => 'phc_short',
    'no prefix' => 'abc'.str_repeat('a', 44),
    'wrong prefix' => 'phx_'.str_repeat('a', 43),
    'too long' => 'phc_'.str_repeat('a', 44),
    'bad chars' => 'phc_'.str_repeat('a', 42).'-',
    'trailing newline' => 'phc_'.str_repeat('a', 43)."\n",
    'huge' => str_repeat('a', 5000),
]);

it('caches positive lookups', function () {
    $project = Project::factory()->create();
    $resolver = app(ProjectResolver::class);

    $resolver->resolve($project->api_key);

    DB::enableQueryLog();
    expect($resolver->resolve($project->api_key))->toBe($project->id)
        ->and(DB::getQueryLog())->toBeEmpty()
        ->and(Cache::get('project_id_by_key:'.$project->api_key))->toBe($project->id);
});

it('negative-caches well-formed unknown keys under a hashed key', function () {
    $key = Project::generateApiKey();
    $resolver = app(ProjectResolver::class);

    expect($resolver->resolve($key))->toBeNull()
        ->and(Cache::has('project_missing:'.sha1($key)))->toBeTrue()
        ->and(Cache::has('project_id_by_key:'.$key))->toBeFalse();

    DB::enableQueryLog();
    expect($resolver->resolve($key))->toBeNull()
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('does not serve a stale negative entry forever', function () {
    $key = Project::generateApiKey();
    $resolver = app(ProjectResolver::class);
    $resolver->resolve($key);

    // Bypasses model events (and so the observer): only the TTL can expire the negative entry.
    DB::table('projects')->insert(['name' => 'late', 'api_key' => $key, 'created_at' => now(), 'updated_at' => now()]);

    $this->travel(9)->seconds();
    expect($resolver->resolve($key))->toBeNull();

    $this->travel(2)->seconds();
    expect($resolver->resolve($key))->not->toBeNull();
});

it('forgets both cache entries', function () {
    $project = Project::factory()->create();
    $missing = Project::generateApiKey();
    $resolver = app(ProjectResolver::class);
    $resolver->resolve($project->api_key);
    $resolver->resolve($missing);

    $resolver->forget($project->api_key);
    $resolver->forget($missing);

    expect(Cache::has('project_id_by_key:'.$project->api_key))->toBeFalse()
        ->and(Cache::has('project_missing:'.sha1($missing)))->toBeFalse();
});

it('falls back to the database when every cache call throws', function () {
    $project = Project::factory()->create();
    Cache::shouldReceive('get', 'has', 'put')->andThrow(new RuntimeException('redis down'));

    expect(app(ProjectResolver::class)->resolve($project->api_key))->toBe($project->id)
        ->and(app(ProjectResolver::class)->resolve(Project::generateApiKey()))->toBeNull();
});

it('lets a database failure propagate', function () {
    DB::beforeExecuting(fn () => throw new RuntimeException('db down'));

    app(ProjectResolver::class)->resolve(Project::generateApiKey());
})->throws(RuntimeException::class, 'db down');

it('generates keys that match the model key pattern', function () {
    expect(Project::generateApiKey())->toMatch(Project::API_KEY_PATTERN)
        ->and(strlen(Project::generateApiKey()))->toBe(strlen(Project::API_KEY_PREFIX) + Project::API_KEY_RANDOM_LENGTH);
});
