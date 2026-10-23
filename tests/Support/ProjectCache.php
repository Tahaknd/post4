<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Test helpers around ProjectResolver's cache entries. They spell out the entry names on purpose:
 * a rename inside the resolver must fail the tests that pin its behaviour.
 */
final class ProjectCache
{
    public static function hasPositive(string $apiKey): bool
    {
        return Cache::has('project_id_by_key:'.$apiKey);
    }

    public static function hasNegative(string $apiKey): bool
    {
        return Cache::has('project_missing:'.sha1($apiKey));
    }

    /** Makes every cache call throw, like an unreachable Redis. */
    public static function breakStore(): void
    {
        Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
        config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);
    }
}
