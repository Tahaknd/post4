<?php

/*
 * Child process of the event-name Redis race tests (InsightEventNamesRedisTest). Not a test itself.
 *
 *   php events_race_worker.php slot    <host> <storePrefix> <seconds>
 *   php events_race_worker.php flight  <host> <storePrefix> <projectId> <search> <seconds>
 *   php events_race_worker.php hammer  <host> <storePrefix> <projectId> <count> <milliseconds> <budgetMs>
 *
 * Boots only what the event list needs (config, log, the Redis backed repository) and:
 *  - slot: takes the global event-name slot, prints "held", keeps it for <seconds>, releases it, prints "released";
 *  - flight: takes the single-flight lock of (project, search), prints "held", after <seconds> stores an answer for it in
 *    the cache and releases the lock, prints "released";
 *  - hammer: asks the event list of the project <count> times with distinct search texts, each ClickHouse read taking
 *    <milliseconds> (a fake client), and prints the HTTP-like outcome of each request (200 or 429).
 */

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\Events\EventNamesBusy;
use App\Domain\Insights\Events\EventNamesSqlBuilder;
use App\Domain\Insights\Runs\InsightGate;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Illuminate\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;
use Tests\Support\RedisIntegration;

require __DIR__.'/../../vendor/autoload.php';

[, $mode, $host, $storePrefix] = $argv;

putenv("REDIS_INTEGRATION_HOST={$host}");

$app = new Application(dirname(__DIR__, 2));
$app->instance('config', new Config([
    'insights' => [
        'events_cache_ttl_seconds' => 120, 'events_empty_search_cache_ttl_seconds' => 300, 'events_max_execution_seconds' => 3,
        'events_lock_ttl_seconds' => 15, 'events_wait_ms' => 1200, 'events_project_budget_ms' => (int) ($argv[7] ?? 10000),
    ],
    'ratelimit' => ['warning_interval_seconds' => 60],
]));
$app->instance('log', new NullLogger);
$app->instance('date', new DateFactory);
$cache = RedisIntegration::repository(RedisIntegration::manager($app), $storePrefix);
Facade::setFacadeApplication($app);

$gate = new InsightGate($cache);

if ($mode === 'slot') {
    $permit = $gate->acquireEventNames();
    echo ($permit === null ? 'busy' : 'held'), "\n";
    sleep((int) $argv[4]);
    $permit?->release();
    echo "released\n";

    exit(0);
}

if ($mode === 'flight') {
    [, , , , $projectId, $search, $seconds] = $argv;
    $permit = $gate->acquireEventNamesFlight((int) $projectId, hash('sha256', $search));
    echo ($permit === null ? 'busy' : 'held'), "\n";
    usleep((int) ((float) $seconds * 1_000_000));
    $cache->put(EventNames::cacheKey((int) $projectId, $search), ['events' => [['name' => 'from-the-holder', 'count' => 42]], 'truncated' => false], 60);
    $permit?->release();
    echo "released\n";

    exit(0);
}

// hammer
[, , , , $projectId, $count, $milliseconds] = $argv;
$reads = new class((int) $milliseconds) implements ClickHouseReads
{
    public function __construct(private readonly int $ms) {}

    public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
    {
        usleep($this->ms * 1000);

        return [['name' => 'x', 'total' => 1]];
    }
};
$names = new EventNames(new ProjectScopedReader($reads), new EventNamesSqlBuilder, $gate, $cache);
$outcomes = [];
for ($i = 0; $i < (int) $count; $i++) {
    try {
        $names->list((int) $projectId, "hammer{$i}");
        $outcomes[] = 200;
    } catch (EventNamesBusy) {
        $outcomes[] = 429;
    }
}
echo json_encode($outcomes), "\n";
