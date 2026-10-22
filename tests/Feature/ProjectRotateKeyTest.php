<?php

use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FailingCacheStore;

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

it('rejects a non-numeric project id without touching the database', function (string $id) {
    DB::enableQueryLog();

    expect(Artisan::call('project:rotate-key', ['id' => $id]))->toBe(1);

    expect(Artisan::output())->toContain('Geçersiz proje kimliği.')
        ->and(DB::getQueryLog())->toBeEmpty();
})->with(['letters' => 'abc', 'negative' => '-1', 'decimal' => '1.5', 'mixed' => '12a', 'whitespace' => ' 1']);

it('rejects an id too long for a bigint', function () {
    expect(Artisan::call('project:rotate-key', ['id' => '99999999999999999999']))->toBe(1);

    expect(Artisan::output())->toContain('Geçersiz proje kimliği.');
});

it('still prints the new key when the cache cannot be cleared', function () {
    $project = Project::factory()->create();
    $old = $project->api_key;
    Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
    config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);

    expect(Artisan::call('project:rotate-key', ['id' => $project->id]))->toBe(0);

    $new = $project->fresh()->api_key;
    expect($new)->not->toBe($old)
        ->and(Artisan::output())->toContain($new);
});
