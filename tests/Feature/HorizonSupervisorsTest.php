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

    expect($options['queue'])->toBe([RunTrendsInsight::QUEUE])->toBe(['insights'])
        ->and($options['connection'])->toBe('redis')
        ->and($options['maxProcesses'])->toBe(2)
        ->and($options['minProcesses'])->toBe(2)
        ->and($options['balance'])->toBe('simple')
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

    expect($queues)->toBe(['default' => ['supervisor-1'], 'maintenance' => ['supervisor-maintenance'], 'insights' => ['supervisor-insights']])
        ->and(PurgeProjectEvents::QUEUE)->toBe('maintenance');
});

it('watches the insights queue for long waits', function () {
    expect(config('horizon.waits'))->toHaveKey('redis:insights');
});
