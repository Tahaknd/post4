<?php

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\Events\EventNamesSqlBuilder;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\Permit;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Tests\Support\ScriptedReads;

/*
 * Locks (the event-name slot, run transitions, single flight) must live on the BOUNDED `cache` Redis connection: the
 * `default` connection has no read timeout (Horizon's blocking pops need that), so a frozen Redis would hang every
 * request that takes a lock instead of failing open.
 */

it('puts the locks of the redis cache store on the bounded cache connection', function () {
    $config = require base_path('config/cache.php');
    $redis = require base_path('config/database.php');

    expect($config['stores']['redis']['connection'])->toBe('cache')
        ->and($config['stores']['redis']['lock_connection'])->toBe('cache')
        ->and($redis['redis']['cache']['timeout'])->toBeFloat()->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0)
        ->and($redis['redis']['cache']['read_timeout'])->toBeFloat()->toBeGreaterThan(0.0)->toBeLessThanOrEqual(2.0)
        ->and($redis['redis']['default'])->not->toHaveKey('read_timeout')
        ->and(file_get_contents(base_path('config/cache.php')))->toContain("env('REDIS_CACHE_LOCK_CONNECTION', 'cache')");
});

/** A TCP server that accepts connections (the kernel queues them) and never answers: a frozen Redis. */
function silentServer(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    expect($server)->not->toBeFalse("could not listen: {$message}");
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    return [$server, $port];
}

/** Points BOTH Redis connections at the silent server and rebuilds the store the way the app does. */
function frozenRedisStore(int $port): void
{
    config([
        'database.redis.cache.host' => '127.0.0.1',
        'database.redis.cache.port' => $port,
        'database.redis.cache.max_retries' => 0,
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => $port,
        'database.redis.default.max_retries' => 0,
        'insights.cache_store' => 'redis',
    ]);
    app()->forgetInstance('redis');
    Facade::clearResolvedInstance('redis');
    Cache::forgetDriver('redis');
}

it('fails open within the cache connection timeouts when the Redis server hangs: the event-name slot is granted', function () {
    [$server, $port] = silentServer();
    frozenRedisStore($port);
    // If a lock ever went to the `default` connection (no read timeout of its own) it would wait for this instead.
    $previous = ini_set('default_socket_timeout', '4');

    try {
        $start = hrtime(true);
        $permit = (new InsightGate(Cache::store('redis')))->acquireEventNames();
        $elapsed = (hrtime(true) - $start) / 1e9;
    } finally {
        ini_set('default_socket_timeout', (string) $previous);
        fclose($server);
    }

    expect($permit)->toBeInstanceOf(Permit::class)->and($elapsed)->toBeLessThan(3.0);
});

it('answers the event list when the Redis server hangs, live and within the timeouts', function () {
    [$server, $port] = silentServer();
    frozenRedisStore($port);
    $previous = ini_set('default_socket_timeout', '4');
    config(['insights.events_wait_ms' => 0]);
    $reads = new ScriptedReads([[['name' => 'signup', 'total' => 4]]]);

    try {
        $names = new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, new InsightGate(Cache::store('redis')), Cache::store('redis'));
        $start = hrtime(true);
        $answer = $names->list(7, 'sig');
        $elapsed = (hrtime(true) - $start) / 1e9;
    } finally {
        ini_set('default_socket_timeout', (string) $previous);
        fclose($server);
    }

    expect($answer)->toBe(['events' => [['name' => 'signup', 'count' => 4]], 'truncated' => false])
        ->and($reads->calls)->toHaveCount(1)
        ->and($elapsed)->toBeLessThan(4.0);
});

it('has no insight code that names the default connection for a lock or reads lock keys from it', function () {
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && preg_match("/lock_connection|setLockConnection|connection\\('default'\\)/", (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([])->and(class_exists(InsightRunner::class))->toBeTrue();
});
