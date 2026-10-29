<?php

namespace Tests\Support;

use App\Domain\Identity\IdentityKind;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Runs identity events in real child processes, each its own application and its own Postgres session (tests/Support/
 * identity_race_worker.php), and releases them together. Helpers of the Integration suite.
 *
 * Workers announce READY and then wait for a barrier file, so a race starts when the LAST worker is booted, not
 * while the others are still loading the framework. Every wait has a deadline and leftover children are terminated
 * (stop()), so a hung worker fails the test instead of hanging the run.
 */
final class IdentityRace
{
    /** @var list<array{label: string, process: resource, out: resource, err: resource}> */
    private static array $workers = [];

    private static string $barrier = '';

    /**
     * Starts one worker per entry of $eventLists (label => list of [project, kind, a, b]), waits for every READY,
     * releases them together and returns each worker's decoded JSON line, by label.
     *
     * @param  array<string, list<array{int, IdentityKind, string, string}>>  $eventLists
     * @return array<string, array<string, mixed>>
     */
    public static function run(array $eventLists, float $seconds = 60): array
    {
        $workers = self::start($eventLists);
        self::release();

        $results = [];
        foreach ($workers as $worker) {
            $results[$worker['label']] = self::result($worker, $seconds);
        }

        return $results;
    }

    /**
     * Starts the workers and waits for each READY, but does not release them: the caller arranges the scene (holds a
     * lock, say), then calls release() and result().
     *
     * @param  array<string, list<array{int, IdentityKind, string, string}>>  $eventLists
     * @return list<array{label: string, process: resource, out: resource, err: resource}>
     */
    public static function start(array $eventLists): array
    {
        self::$barrier = sys_get_temp_dir().'/post4-identity-barrier-'.bin2hex(random_bytes(5));
        @unlink(self::$barrier);

        $workers = [];
        foreach ($eventLists as $label => $events) {
            $workers[] = self::spawn((string) $label, $events);
        }

        foreach ($workers as $worker) {
            Assert::assertSame('READY', self::line($worker, 60), "worker {$worker['label']} did not become ready");
        }

        return $workers;
    }

    public static function release(): void
    {
        touch(self::$barrier);
    }

    /** @return array<string, mixed> */
    public static function result(array $worker, float $seconds = 60): array
    {
        return json_decode(self::line($worker, $seconds), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function isRunning(array $worker): bool
    {
        return proc_get_status($worker['process'])['running'];
    }

    /** Terminates every worker still alive and removes the barrier. Call it from afterEach. */
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

    /** Backends of the test database waiting for an advisory lock right now. */
    public static function advisoryWaiters(): int
    {
        return (int) DB::scalar("select count(*) from pg_locks
            where locktype = 'advisory' and not granted
              and database = (select oid from pg_database where datname = current_database())");
    }

    /** Polls until at least $count backends wait for an advisory lock; fails after the deadline. */
    public static function awaitAdvisoryWaiters(int $count, float $seconds = 20): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (self::advisoryWaiters() >= $count) {
                return;
            }

            usleep(30_000);
        }

        Assert::fail("no backend waited for an advisory lock within {$seconds}s (waiting: ".self::advisoryWaiters().')');
    }

    /** @param  list<array{int, IdentityKind, string, string}>  $events */
    private static function spawn(string $label, array $events): array
    {
        $connection = (array) config('database.connections.pgsql');

        // The worker is not a PHPUnit process: it gets exactly the pinned test database, and nothing else of this
        // environment, so it cannot reach the live services. (It re-checks the rule itself before doing anything.)
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
        ];

        $specs = array_map(fn (array $event) => ['project' => $event[0], 'kind' => $event[1]->value, 'a' => $event[2], 'b' => $event[3]], $events);

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/identity_race_worker.php', $label, self::$barrier, json_encode($specs, JSON_THROW_ON_ERROR)],
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

    /** One line from the worker's stdout within $seconds, or a failure carrying its stderr. */
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
