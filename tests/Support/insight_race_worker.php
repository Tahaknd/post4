<?php

/*
 * Child process of the insight Redis race tests (InsightRunStoreRedisTest). Not a test itself.
 *
 *   php insight_race_worker.php slots  <host> <storePrefix> <projectId> <tries> <startAt>
 *   php insight_race_worker.php done   <host> <storePrefix> <startAt> <runId>...
 *   php insight_race_worker.php cancel <host> <storePrefix> <startAt> <runId>...
 *
 * Boots only what the insight store and gates need (config, log, the Redis backed repository), waits for <startAt>
 * so that all workers start together, then:
 *  - slots: tries to take <tries> project dispatch slots (each under its own owner) and prints how many it got;
 *  - done / cancel: applies that transition to every run id and prints {runId: bool applied}.
 */

use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use Illuminate\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;
use Tests\Support\RedisIntegration;

require __DIR__.'/../../vendor/autoload.php';

[, $mode, $host, $storePrefix] = $argv;

putenv("REDIS_INTEGRATION_HOST={$host}");

$app = new Application(dirname(__DIR__, 2));
$app->instance('config', new Config([
    'insights' => [
        'run_ttl_seconds' => 900, 'finished_ttl_seconds' => 300, 'max_result_bytes' => 1_000_000,
        'max_active_runs_per_project' => 3, 'max_active_runs_per_user' => 3, 'lock_ttl_seconds' => 50,
    ],
    'ratelimit' => ['warning_interval_seconds' => 60],
]));
$app->instance('log', new NullLogger);
$cache = RedisIntegration::repository(RedisIntegration::manager($app), $storePrefix);
Facade::setFacadeApplication($app);

if ($mode === 'slots') {
    [, , , , $projectId, $tries, $startAt] = $argv;
    $gate = new InsightGate($cache);
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }
    $got = 0;
    for ($i = 0; $i < (int) $tries; $i++) {
        if ($gate->acquireProjectSlot((int) $projectId, bin2hex(random_bytes(8))) !== null) {
            $got++;
        }
    }
    echo json_encode(['got' => $got]), "\n";

    exit(0);
}

[, , , , $startAt] = $argv;
$runIds = array_slice($argv, 5);
$store = new InsightRunStore($cache);
while (microtime(true) < (float) $startAt) {
    usleep(200);
}
$applied = [];
foreach ($runIds as $runId) {
    $applied[$runId] = $mode === 'done'
        ? $store->markDone($runId, ['timezone' => 'UTC', 'buckets' => [], 'labels' => [], 'series' => []])
        : $store->cancel($runId);
}
echo json_encode($applied), "\n";
