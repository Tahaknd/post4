<?php

use App\Domain\Users\UserCreationLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Runs the user-creation lock against a real Postgres, because advisory locks exist only there (the default
 * SQLite test database cannot try one). Opt-in: the file is in the group "postgres", which phpunit.xml excludes
 * from the default run. Run it with
 *
 *   docker compose exec -T -e POSTGRES_INTEGRATION_HOST=postgres app php artisan test --group=postgres
 *
 * It creates a scratch database (post4_race_<random>) next to the application's, runs the application's real
 * migrations on it, never touches the application's own tables, and drops the scratch database afterwards even
 * when a test fails half way. It uses DB_USERNAME and DB_PASSWORD from the environment (the Compose values) and
 * real child processes, so the competing creations are separate database sessions.
 *
 * It must not pass by accident:
 *  - "blocked" is observed in pg_locks (a backend waiting for the advisory lock), not guessed from a sleep;
 *  - workers announce READY and start on a barrier file, so they are released together;
 *  - a negative control runs the same race WITHOUT the lock and must produce more than one administrator;
 *  - every wait has a deadline, and leftover child processes are terminated.
 */

pest()->group('postgres');

function raceHost(): string
{
    $host = getenv('POSTGRES_INTEGRATION_HOST');

    if (! is_string($host) || $host === '') {
        test()->fail('The postgres group needs a real Postgres: set POSTGRES_INTEGRATION_HOST, e.g. '
            .'docker compose exec -T -e POSTGRES_INTEGRATION_HOST=postgres app php artisan test --group=postgres');
    }

    return $host;
}

function raceUser(): string
{
    return (string) (getenv('DB_USERNAME') ?: 'posthog');
}

function racePassword(): string
{
    return (string) (getenv('DB_PASSWORD') ?: 'posthog');
}

function racePdo(string $database): PDO
{
    $pdo = new PDO('pgsql:host='.raceHost().";port=5432;dbname={$database}", raceUser(), racePassword());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

/**
 * Keeps the started workers so afterEach can terminate stragglers; with an argument registers one, without
 * returns the list and forgets it.
 */
function raceWorkers(?array $worker = null): array
{
    static $workers = [];

    if ($worker !== null) {
        $workers[] = $worker;

        return $workers;
    }

    $all = $workers;
    $workers = [];

    return $all;
}

/** Starts a worker against the scratch database; returns its process, pipes and label. */
function startRaceWorker(string $database, string $label, string $mode, string $barrier): array
{
    $env = [
        'PATH' => (string) getenv('PATH'),
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:Ce5G4GmiUVXannm654GanbHgZ1SueerpQTjdyJxXfoI=',
        'BCRYPT_ROUNDS' => '4',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => raceHost(),
        'DB_PORT' => '5432',
        'DB_DATABASE' => $database,
        'DB_USERNAME' => raceUser(),
        'DB_PASSWORD' => racePassword(),
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'LOG_CHANNEL' => 'null',
    ];

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__).'/Support/register_race_worker.php', $label, $mode, $barrier],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2),
        $env,
    );

    expect($process)->not->toBeFalse();

    $worker = ['label' => $label, 'process' => $process, 'out' => $pipes[1], 'err' => $pipes[2]];
    raceWorkers($worker);

    return $worker;
}

/** Reads one line from the worker's stdout within $seconds, or fails with its stderr. */
function readRaceLine(array $worker, float $seconds): string
{
    $deadline = microtime(true) + $seconds;
    $buffer = '';

    while (microtime(true) < $deadline) {
        $read = [$worker['out']];
        $write = $except = null;

        if (stream_select($read, $write, $except, 0, 200_000) > 0) {
            $chunk = fgets($worker['out']);

            if ($chunk === false) {
                break;
            }

            $buffer .= $chunk;

            if (str_contains($buffer, "\n")) {
                return trim($buffer);
            }
        }
    }

    test()->fail("worker {$worker['label']} printed no line in {$seconds}s. stdout: {$buffer} stderr: ".stream_get_contents($worker['err']));
}

/** Starts the workers, waits for each READY, releases them together and returns their decoded results. */
function raceTogether(string $database, array $modes, string $barrier): array
{
    @unlink($barrier);

    $workers = [];
    foreach ($modes as $label => $mode) {
        $workers[] = startRaceWorker($database, (string) $label, $mode, $barrier);
    }

    foreach ($workers as $worker) {
        expect(readRaceLine($worker, 30))->toBe('READY');
    }

    touch($barrier);

    return array_map(fn (array $worker) => json_decode(readRaceLine($worker, 30), true), $workers);
}

/** Backends of the scratch database currently waiting for an advisory lock. */
function waitingForAdvisoryLock(PDO $pdo): int
{
    return (int) $pdo->query("select count(*) from pg_locks
        where locktype = 'advisory' and not granted
          and database = (select oid from pg_database where datname = current_database())")->fetchColumn();
}

/** Polls until at least $count backends wait for the advisory lock; fails after the deadline. */
function awaitAdvisoryWaiters(PDO $pdo, int $count, float $seconds = 15): void
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        if (waitingForAdvisoryLock($pdo) >= $count) {
            return;
        }

        usleep(50_000);
    }

    test()->fail("no backend waited for the advisory lock within {$seconds}s (waiting: ".waitingForAdvisoryLock($pdo).')');
}

beforeEach(function () {
    raceWorkers();
    $this->barrier = sys_get_temp_dir().'/post4-race-barrier-'.bin2hex(random_bytes(5));
    $this->database = 'post4_race_'.bin2hex(random_bytes(5));
    $this->created = false;
    $this->scratch = null;

    // Set before anything else can fail: afterEach drops the database whenever the name is known.
    $this->created = true;
    racePdo('postgres')->exec("create database {$this->database}");

    // The application's real migrations, not hand-written DDL.
    config(['database.connections.scratch' => [
        'driver' => 'pgsql',
        'host' => raceHost(),
        'port' => '5432',
        'database' => $this->database,
        'username' => raceUser(),
        'password' => racePassword(),
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ]]);
    expect(Artisan::call('migrate', ['--database' => 'scratch', '--force' => true]))->toBe(0);

    $this->scratch = racePdo($this->database);
});

afterEach(function () {
    foreach (raceWorkers() as $worker) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
    }

    @unlink($this->barrier);
    $this->scratch = null;
    DB::purge('scratch');

    if ($this->created) {
        // WITH (FORCE) disconnects any straggler session of the scratch database.
        racePdo('postgres')->exec("drop database if exists {$this->database} with (force)");
    }
});

it('ran the real migrations on the scratch database', function () {
    $columns = $this->scratch->query("select column_name from information_schema.columns where table_name = 'users'")->fetchAll(PDO::FETCH_COLUMN);

    expect($columns)->toContain('is_admin', 'email', 'password');
});

it('blocks a first registration on the advisory lock until the competing transaction commits, then refuses it', function () {
    // This session plays a registration that is in the middle of its transaction.
    $this->scratch->beginTransaction();
    $this->scratch->prepare('select pg_advisory_xact_lock(?)')->execute([UserCreationLock::KEY]);

    $worker = startRaceWorker($this->database, 'late', 'register', $this->barrier);
    expect(readRaceLine($worker, 30))->toBe('READY');
    touch($this->barrier);

    awaitAdvisoryWaiters($this->scratch, 1);
    expect(proc_get_status($worker['process'])['running'])->toBeTrue('the worker finished although the lock was held');

    $this->scratch->exec("insert into users (name, email, password, is_admin) values ('First', 'first@example.test', 'x', true)");
    $this->scratch->commit();

    $result = json_decode(readRaceLine($worker, 30), true);

    expect($result['result'])->toBe('closed')
        ->and((int) $this->scratch->query('select count(*) from users')->fetchColumn())->toBe(1)
        ->and((int) $this->scratch->query('select count(*) from users where is_admin')->fetchColumn())->toBe(1);
});

it('makes user:create wait for the same lock', function () {
    $this->scratch->beginTransaction();
    $this->scratch->prepare('select pg_advisory_xact_lock(?)')->execute([UserCreationLock::KEY]);

    $worker = startRaceWorker($this->database, 'operator', 'create', $this->barrier);
    expect(readRaceLine($worker, 30))->toBe('READY');
    touch($this->barrier);

    awaitAdvisoryWaiters($this->scratch, 1);
    expect(proc_get_status($worker['process'])['running'])->toBeTrue();

    $this->scratch->commit();

    expect(json_decode(readRaceLine($worker, 30), true)['result'])->toBe('created')
        ->and((int) $this->scratch->query('select count(*) from users')->fetchColumn())->toBe(1);
});

it('lets exactly one of several simultaneous first registrations win, round after round', function () {
    foreach (range(1, 4) as $round) {
        $this->scratch->exec('truncate users restart identity cascade');

        $results = raceTogether($this->database, ["a{$round}" => 'register', "b{$round}" => 'register', "c{$round}" => 'register', "d{$round}" => 'register'], $this->barrier);

        $outcomes = array_count_values(array_column($results, 'result'));
        $context = "round {$round}: ".json_encode($results);

        expect($outcomes['created'] ?? 0)->toBe(1, $context)
            ->and($outcomes['closed'] ?? 0)->toBe(3, $context)
            ->and((int) $this->scratch->query('select count(*) from users')->fetchColumn())->toBe(1)
            ->and((int) $this->scratch->query('select count(*) from users where is_admin')->fetchColumn())->toBe(1);
    }
});

it('registers at most one administrator while user:create runs at the same time', function () {
    foreach (range(1, 3) as $round) {
        $this->scratch->exec('truncate users restart identity cascade');

        $results = raceTogether($this->database, ["reg{$round}" => 'register', "op{$round}" => 'create', "reg2{$round}" => 'register'], $this->barrier);

        $registered = collect($results)->filter(fn ($r) => str_starts_with($r['label'], 'reg') && $r['result'] === 'created')->count();
        $closed = collect($results)->filter(fn ($r) => str_starts_with($r['label'], 'reg') && $r['result'] === 'closed')->count();

        // The operator's explicit admin is always created; of the two registrations at most one can win,
        // and only when it ran before every other creation committed.
        expect($registered)->toBeLessThanOrEqual(1, json_encode($results))
            ->and($registered + $closed)->toBe(2, json_encode($results))
            ->and(collect($results)->where('result', 'error')->count())->toBe(0, json_encode($results));
    }
});

it('detects two administrators when nothing serialises the creations (negative control)', function () {
    $results = raceTogether($this->database, ['n1' => 'register_nolock', 'n2' => 'register_nolock', 'n3' => 'register_nolock'], $this->barrier);

    $admins = (int) $this->scratch->query('select count(*) from users where is_admin')->fetchColumn();

    // If this ever fails the harness has lost its power to see a broken lock, and the other tests prove nothing.
    expect($admins)->toBeGreaterThan(1, json_encode($results));
});
