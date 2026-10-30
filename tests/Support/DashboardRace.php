<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Runs dashboard actions in real child processes (tests/Support/dashboard_race_worker.php), each its own application and its
 * own Postgres session, released together from a barrier file. Helper of the Integration suite; every wait has a deadline and
 * leftover children are terminated by stop().
 */
final class DashboardRace
{
    /** @var list<array{label: string, process: resource, out: resource, err: resource}> */
    private static array $workers = [];

    private static string $barrier = '';

    /**
     * @param  array<string, list<array<string, mixed>>>  $opLists  label => operations
     * @return array<string, array<string, mixed>> the decoded line of each worker, by label
     */
    public static function run(array $opLists, float $seconds = 60): array
    {
        self::$barrier = sys_get_temp_dir().'/post4-dashboard-barrier-'.bin2hex(random_bytes(5));
        @unlink(self::$barrier);

        $workers = [];
        foreach ($opLists as $label => $ops) {
            $workers[] = self::spawn((string) $label, $ops);
        }
        foreach ($workers as $worker) {
            Assert::assertSame('READY', self::line($worker, 60), "worker {$worker['label']} did not become ready");
        }

        touch(self::$barrier);

        $results = [];
        foreach ($workers as $worker) {
            $results[$worker['label']] = json_decode(self::line($worker, $seconds), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    /** Every operation result of every worker, flattened. @param  array<string, array<string, mixed>>  $results @return list<array<string, mixed>> */
    public static function all(array $results): array
    {
        $all = [];
        foreach ($results as $label => $result) {
            Assert::assertArrayNotHasKey('error', $result, "worker {$label}: ".json_encode($result));
            foreach ($result['results'] as $one) {
                $all[] = $one;
            }
        }

        return $all;
    }

    /** Fails when any operation ended in anything but success or a validation refusal (a deadlock is SQLSTATE 40P01, a unique violation 23505). */
    public static function assertNoErrors(array $results): void
    {
        foreach (self::all($results) as $one) {
            Assert::assertArrayNotHasKey('error', $one, json_encode($one));
        }
    }

    public static function stop(): void
    {
        foreach (self::$workers as $worker) {
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }
        self::$workers = [];

        if (self::$barrier !== '') {
            @unlink(self::$barrier);
        }
    }

    /** @param  list<array<string, mixed>>  $ops */
    private static function spawn(string $label, array $ops): array
    {
        $connection = (array) config('database.connections.pgsql');

        $env = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) (getenv('HOME') ?: '/tmp'),
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'DB_URL' => '',
            'TESTS_ALLOW_PGSQL' => (string) getenv('TESTS_ALLOW_PGSQL'),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'null',
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => '6399',
            // the limits of the scenario, inherited from the parent's config
            'DASHBOARDS_MAX_TILES_PER_DASHBOARD' => (string) config('dashboards.max_tiles_per_dashboard'),
            'DASHBOARDS_MAX_SAVED_INSIGHTS_PER_PROJECT' => (string) config('dashboards.max_saved_insights_per_project'),
            'DASHBOARDS_MAX_DASHBOARDS_PER_PROJECT' => (string) config('dashboards.max_dashboards_per_project'),
        ];

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/dashboard_race_worker.php', $label, self::$barrier, json_encode($ops, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $env,
        );

        Assert::assertNotFalse($process, "could not start worker {$label}");

        $worker = ['label' => $label, 'process' => $process, 'out' => $pipes[1], 'err' => $pipes[2]];
        self::$workers[] = $worker;

        return $worker;
    }

    private static function line(array $worker, float $seconds): string
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $read = [$worker['out']];
            $write = $except = null;

            if (stream_select($read, $write, $except, 0, 200_000) > 0) {
                $line = fgets($worker['out']);
                if ($line === false) {
                    break;
                }

                return trim($line);
            }
        }

        stream_set_blocking($worker['err'], false);

        Assert::fail("worker {$worker['label']} printed no line in {$seconds}s. stderr: ".stream_get_contents($worker['err']));
    }
}
