<?php

it('runs against an isolated in-memory sqlite database', function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and(config('database.connections.sqlite.url'))->toBeEmpty();
});

it('uses array/sync drivers and the testing environment', function () {
    expect(config('cache.default'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('session.driver'))->toBe('array')
        ->and(app()->environment())->toBe('testing');
});
