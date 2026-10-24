<?php

use App\Domain\Users\UserCreationLock;

/*
 * Runs RegisterFirstUser against a real Postgres, because the advisory lock exists only there (the default
 * SQLite test database cannot try it). Opt-in: the file is in the group "postgres", which phpunit.xml excludes
 * from the default run. Run it with
 *
 *   docker compose exec -T -e POSTGRES_INTEGRATION_HOST=postgres app php artisan test --group=postgres
 *
 * It creates a scratch database (post4_race_<random>) next to the application's, never touches the
 * application's own tables, and drops the scratch database at the end. It uses DB_USERNAME and DB_PASSWORD
 * from the environment (the Compose values), and real child processes so that the competing registrations
 * are separate database sessions.
 */

pest()->group('postgres');

function raceAdminDsn(string $database): PDO
{
    $host = getenv('POSTGRES_INTEGRATION_HOST');

    if (! is_string($host) || $host === '') {
        test()->fail('The postgres group needs a real Postgres: set POSTGRES_INTEGRATION_HOST, e.g. '
            .'docker compose exec -T -e POSTGRES_INTEGRATION_HOST=postgres app php artisan test --group=postgres');
    }

    $pdo = new PDO("pgsql:host={$host};port=5432;dbname={$database}", raceUser(), racePassword());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function raceUser(): string
{
    return (string) (getenv('DB_USERNAME') ?: 'posthog');
}

function racePassword(): string
{
    return (string) (getenv('DB_PASSWORD') ?: 'posthog');
}

/** Starts the worker script against the scratch database; returns [process, stdout pipe]. */
function startRaceWorker(string $database, float $startAt, string $label): array
{
    $env = [
        'PATH' => (string) getenv('PATH'),
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:Ce5G4GmiUVXannm654GanbHgZ1SueerpQTjdyJxXfoI=',
        'BCRYPT_ROUNDS' => '4',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) getenv('POSTGRES_INTEGRATION_HOST'),
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
        [PHP_BINARY, __DIR__.'/../Support/register_race_worker.php', (string) $startAt, $label],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2),
        $env,
    );

    return [$process, $pipes];
}

/** Waits for the worker and returns its decoded JSON line. */
function finishRaceWorker($process, array $pipes): array
{
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($process);

    $decoded = json_decode(trim($out), true);

    expect($decoded)->toBeArray("worker printed no JSON. stdout: {$out} stderr: {$err}");

    return $decoded;
}

beforeEach(function () {
    $this->database = 'post4_race_'.bin2hex(random_bytes(5));
    raceAdminDsn('postgres')->exec("create database {$this->database}");

    $this->scratch = raceAdminDsn($this->database);
    $this->scratch->exec('create table users (
        id bigserial primary key,
        name varchar(255) not null,
        email varchar(255) not null unique,
        email_verified_at timestamp null,
        password varchar(255) not null,
        is_admin boolean not null default false,
        remember_token varchar(100) null,
        created_at timestamp null,
        updated_at timestamp null
    )');
});

afterEach(function () {
    if (isset($this->scratch)) {
        $this->scratch = null;
        // Other sessions of the scratch database are gone by now; WITH (FORCE) covers a straggler.
        raceAdminDsn('postgres')->exec("drop database if exists {$this->database} with (force)");
    }
});

it('blocks a first registration on the advisory lock until the competing transaction commits, then refuses it', function () {
    // This session plays a registration that is in the middle of its transaction.
    $this->scratch->beginTransaction();
    $this->scratch->prepare('select pg_advisory_xact_lock(?)')->execute([UserCreationLock::KEY]);

    [$process, $pipes] = startRaceWorker($this->database, microtime(true), 'late');

    usleep(1_500_000);
    expect(proc_get_status($process)['running'])->toBeTrue('the worker did not wait for the lock');

    $this->scratch->exec("insert into users (name, email, password, is_admin) values ('First', 'first@example.test', 'x', true)");
    $this->scratch->commit();

    $result = finishRaceWorker($process, $pipes);

    expect($result['result'])->toBe('closed')
        ->and((int) $this->scratch->query('select count(*) from users')->fetchColumn())->toBe(1)
        ->and((int) $this->scratch->query('select count(*) from users where is_admin')->fetchColumn())->toBe(1);
});

it('lets exactly one of several simultaneous first registrations win, round after round', function () {
    foreach (range(1, 4) as $round) {
        $this->scratch->exec('truncate users restart identity');
        $startAt = microtime(true) + 1.5;

        $workers = array_map(
            fn (string $label) => startRaceWorker($this->database, $startAt, "{$label}{$round}"),
            ['a', 'b', 'c', 'd'],
        );

        $results = array_map(fn (array $worker) => finishRaceWorker(...$worker), $workers);

        $outcomes = array_count_values(array_column($results, 'result'));

        expect($outcomes['created'] ?? 0)->toBe(1, "round {$round}: ".json_encode($results))
            ->and($outcomes['closed'] ?? 0)->toBe(3, "round {$round}: ".json_encode($results))
            ->and((int) $this->scratch->query('select count(*) from users')->fetchColumn())->toBe(1)
            ->and((int) $this->scratch->query('select count(*) from users where is_admin')->fetchColumn())->toBe(1);
    }
});
