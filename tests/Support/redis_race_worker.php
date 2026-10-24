<?php

/*
 * Child process of the Redis race test (ProjectRateLimiterRedisTest). Not a test itself.
 *
 *   php redis_race_worker.php <host> <storePrefix> <projectId> <ts> <limit> <maxBatch> <rounds> <seed> <startAt>
 *
 * Boots only what the limiter needs (config, log, cache facade), waits for <startAt> so that all workers
 * start together, then does <rounds> reservations of random size, releasing part of some accepted ones.
 * Prints one JSON line: the events it kept (reserved minus released) and how many reservations were rejected.
 */

use App\Domain\Capture\ProjectRateLimiter;
use Illuminate\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;
use Tests\Support\RedisIntegration;

require __DIR__.'/../../vendor/autoload.php';

[, $host, $storePrefix, $projectId, $ts, $limit, $maxBatch, $rounds, $seed, $startAt] = $argv;

putenv("REDIS_INTEGRATION_HOST={$host}");

$app = new Application(dirname(__DIR__, 2));
$app->instance('config', new Config([
    'capture' => [
        'project_events_per_minute' => (int) $limit,
        'max_batch_size' => (int) $maxBatch,
    ],
    'ratelimit' => ['warning_interval_seconds' => 60],
]));
$app->instance('log', new NullLogger);
$app->instance('cache', RedisIntegration::repository(RedisIntegration::manager($app), $storePrefix));
Facade::setFacadeApplication($app);

$limiter = new ProjectRateLimiter(static fn (): int => 0);
mt_srand((int) $seed);

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

$kept = 0;
$rejected = 0;
for ($i = 0; $i < (int) $rounds; $i++) {
    $size = mt_rand(1, (int) $maxBatch);
    if ($limiter->reserve((int) $projectId, $size, (int) $ts) !== null) {
        $rejected++;

        continue;
    }

    $released = mt_rand(0, 2) === 0 ? mt_rand(0, $size) : 0;
    if ($released > 0) {
        $limiter->release((int) $projectId, $released, (int) $ts);
    }
    $kept += $size - $released;
}

echo json_encode(['kept' => $kept, 'rejected' => $rejected]), "\n";
