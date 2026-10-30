<?php

use App\Jobs\PurgeProjectEvents;
use App\Jobs\RunTrendsInsight;

/** The effective options of a supervisor in an environment: the defaults overridden by the environment entry. */
function supervisor(string $environment, string $name): array
{
    return [...config("horizon.defaults.{$name}"), ...config("horizon.environments.{$environment}.{$name}", [])];
}

it('runs a supervisor for the insights queue in every environment with exactly two processes', function (string $environment) {
    $options = supervisor($environment, 'supervisor-insights');

    // interactive first, the scheduled dashboard refresh second: the order is the priority
    expect($options['queue'])->toBe([RunTrendsInsight::QUEUE, 'insights-refresh'])->toBe(['insights', 'insights-refresh'])
        ->and(config('dashboards.refresh.queue'))->toBe('insights-refresh')
        ->and($options['connection'])->toBe('redis')
        ->and($options['maxProcesses'])->toBe(2)
        ->and($options['minProcesses'])->toBe(2)
        // false = every process serves the queues in the order listed, so a refresh never waits ahead of a chart
        ->and($options['balance'])->toBeFalse()
        // Two workers < max_concurrent_queries_for_user = 3, which every tenant shares.
        ->and($options['maxProcesses'])->toBeLessThan(3)
        ->and($options['tries'])->toBe(1)
        ->and($options['timeout'])->toBe(60)
        // an idle worker polls every second: a user is waiting for the chart
        ->and($options['sleep'])->toBe(1);
})->with(['production', 'local', '*']);

it('keeps the job timeout below the supervisor timeout and the Redis retry_after', function () {
    $job = new RunTrendsInsight(1, str_repeat('a', 32), [], 1);

    expect($job->timeout)->toBeLessThan(supervisor('*', 'supervisor-insights')['timeout'])
        ->and(supervisor('*', 'supervisor-insights')['timeout'])->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});

it('gives every queue its own supervisor so the insights workers never serve other jobs', function () {
    $queues = [];
    foreach (['supervisor-1', 'supervisor-maintenance', 'supervisor-insights'] as $name) {
        foreach (supervisor('*', $name)['queue'] as $queue) {
            $queues[$queue][] = $name;
        }
    }

    expect($queues)->toBe(['default' => ['supervisor-1'], 'maintenance' => ['supervisor-maintenance'], 'insights' => ['supervisor-insights'], 'insights-refresh' => ['supervisor-insights']])
        ->and(PurgeProjectEvents::QUEUE)->toBe('maintenance');
});

it('watches the insights queue for long waits', function () {
    expect(config('horizon.waits'))->toHaveKey('redis:insights')->toHaveKey('redis:insights-refresh');
});

it('pins the insight capacity defaults that the worker arithmetic relies on', function () {
    $config = require base_path('config/insights.php');
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    expect($config['max_active_runs_per_user'])->toBe(8)
        ->and($config['max_active_runs_per_project'])->toBe(20)
        // one project can never hold both of the two workers, and 2 workers < 3 ClickHouse slots leaves one spare
        ->and($config['max_concurrent_queries_per_project'])->toBe(1)
        ->and($config['lock_ttl_seconds'])->toBe(50)->toBeGreaterThan((new RunTrendsInsight(1, str_repeat('a', 32), [], 1))->timeout)->toBeLessThan($retryAfter)
        ->and($config['run_ttl_seconds'])->toBeGreaterThan($config['finished_ttl_seconds'])
        ->and($config['flightfail_ttl_seconds'])->toBeLessThanOrEqual(15)
        ->and($config['inflight_ttl_seconds'])->toBeLessThanOrEqual($config['lock_ttl_seconds'] + 15)
        ->and(file_get_contents(base_path('config/insights.php')))->toContain("env('INSIGHTS_MAX_ACTIVE_RUNS_PER_USER'")->toContain("env('INSIGHTS_LOCK_TTL_SECONDS'")
        ->and(config('horizon.defaults.supervisor-insights.maxProcesses'))->toBe(2);
});
