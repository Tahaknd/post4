<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient;
use App\Infrastructure\ClickHouse\RoSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'clickhouse.url' => 'http://ch:8123',
        'clickhouse.database' => 'posthog',
        'clickhouse.user' => 'admin_user',
        'clickhouse.password' => 'admin_pass',
        'clickhouse.timeout' => 30,
        'clickhouse.connect_timeout' => 10,
        'clickhouse.readonly.user' => 'posthog_ro',
        'clickhouse.readonly.password' => 'ro_pass',
        'clickhouse.readonly.timeout' => 25,
    ]);
    app()->forgetInstance(ClickHouseClient::class);
    app()->forgetInstance(ReadOnlyClickHouseClient::class);
});

function authorizationOf(Request $request): string
{
    return $request->header('Authorization')[0];
}

it('resolves the final read-only client as its own shared singleton', function () {
    expect((new ReflectionClass(ReadOnlyClickHouseClient::class))->isFinal())->toBeTrue()
        ->and(app()->isShared(ReadOnlyClickHouseClient::class))->toBeTrue()
        ->and(app(ReadOnlyClickHouseClient::class))->toBeInstanceOf(ClickHouseReads::class)
        ->and(app(ReadOnlyClickHouseClient::class))->not->toBeInstanceOf(ClickHouseClient::class)
        ->and(app(ReadOnlyClickHouseClient::class))->toBe(app(ReadOnlyClickHouseClient::class))
        ->and(app(ReadOnlyClickHouseClient::class))->not->toBe(app(ClickHouseClient::class));
});

it('connects with the read-only credentials and database, not the admin ones', function () {
    Http::fake(['*' => Http::response('{"data":[{"n":1}]}')]);

    $rows = app(ReadOnlyClickHouseClient::class)->select('SELECT 1 AS n');

    expect($rows)->toBe([['n' => 1]]);
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://ch:8123?')
        && str_contains($r->url(), 'database=posthog')
        && authorizationOf($r) === 'Basic '.base64_encode('posthog_ro:ro_pass'));
});

it('uses the read-only timeout from config, with the connect timeout, and honours a per-call override', function () {
    config(['clickhouse.readonly.timeout' => 7, 'clickhouse.connect_timeout' => 3]);
    app()->forgetInstance(ReadOnlyClickHouseClient::class);
    $seen = [];
    Http::fake(function ($request, $options) use (&$seen) {
        $seen[] = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

        return Http::response('{"data":[]}');
    });

    $client = app(ReadOnlyClickHouseClient::class);
    $client->select('SELECT 1');
    $client->select('SELECT 2', timeout: 12);

    expect($seen)->toBe([[7, 3], [12, 3]]);
});

it('leaves the admin client on the admin credentials and timeout', function () {
    $seen = [];
    Http::fake(function ($request, $options) use (&$seen) {
        $seen[] = $options['timeout'] ?? null;

        return Http::response('');
    });

    app(ClickHouseClient::class)->execute('CREATE TABLE a (x UInt8) ENGINE = Memory');

    expect($seen)->toBe([30]);
    Http::assertSent(fn (Request $r) => authorizationOf($r) === 'Basic '.base64_encode('admin_user:admin_pass'));
});

it('has no execute method at all', function () {
    expect(method_exists(ReadOnlyClickHouseClient::class, 'execute'))->toBeFalse()
        ->and(get_class_methods(ReadOnlyClickHouseClient::class))->toBe(['__construct', 'select']);
});

it('does not let the migrator receive the read-only client', function () {
    $migrator = new ReflectionProperty(ClickHouseMigrator::class, 'clickhouse');

    $client = $migrator->getValue(app(ClickHouseMigrator::class));

    expect($client)->toBeInstanceOf(ClickHouseClient::class)
        ->and($client)->not->toBeInstanceOf(ReadOnlyClickHouseClient::class);
});

it('sends allow-listed settings through select', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    app(ReadOnlyClickHouseClient::class)->select('SELECT 1', [], [
        RoSettings::MAX_EXECUTION_TIME => 5,
        RoSettings::MAX_RESULT_ROWS => 100,
        RoSettings::MAX_RESULT_BYTES => 1000,
        RoSettings::MAX_ROWS_TO_READ => 1000,
        RoSettings::MAX_MEMORY_USAGE => 1000,
        RoSettings::MAX_THREADS => 2,
        RoSettings::LOG_COMMENT => 'project:1',
        RoSettings::SQL_PROJECT_ID => 5,
    ]);

    Http::assertSent(function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $q['max_execution_time'] === '5' && $q['max_result_rows'] === '100'
            && $q['max_result_bytes'] === '1000' && $q['max_rows_to_read'] === '1000'
            && $q['max_memory_usage'] === '1000' && $q['max_threads'] === '2'
            && $q['log_comment'] === 'project:1' && $q['SQL_project_id'] === '5';
    });
});

it('lists exactly the allow-listed settings', function () {
    expect(RoSettings::ALLOWED)->toBe([
        'max_execution_time', 'max_result_rows', 'max_result_bytes', 'max_rows_to_read',
        'max_memory_usage', 'max_threads', 'log_comment', 'SQL_project_id',
    ]);
});

it('rejects every setting outside the allow-list and sends nothing', function (string $name) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => app(ReadOnlyClickHouseClient::class)->select('SELECT 1', [], [$name => 1]))
        ->toThrow(InvalidArgumentException::class, $name);
    Http::assertNothingSent();
})->with([
    // client arguments and HTTP interface arguments that are not query settings
    'database', 'default_format', 'wait_end_of_query', 'query', 'param_project_id',
    'session_id', 'quota_key', 'role', 'user', 'password', 'query_id', 'compress', 'decompress',
    // real settings that are pinned or outside the contract
    'sql_project_id', 'SQL_other',
    'readonly', 'max_bytes_to_read', 'join_algorithm', 'use_query_cache', 'log_queries',
    'max_concurrent_queries_for_user', 'timeout_overflow_mode', 'result_overflow_mode',
]);

it('accepts only an integer SQL_project_id from 1 to 4294967295', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    foreach ([1, 4294967295] as $id) {
        app(ReadOnlyClickHouseClient::class)->select('SELECT 1', [], [RoSettings::SQL_PROJECT_ID => $id]);
    }
    Http::assertSentCount(2);
});

it('rejects an SQL_project_id outside 1..4294967295 or of another type and sends nothing', function (mixed $value) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => app(ReadOnlyClickHouseClient::class)->select('SELECT 1', [], [RoSettings::SQL_PROJECT_ID => $value]))
        ->toThrow(InvalidArgumentException::class, 'SQL_project_id');
    Http::assertNothingSent();
})->with([
    'zero' => [0],
    'negative' => [-1],
    'too big' => [4294967296],
    'numeric string' => ['1'],
    'float' => [1.0],
    'true' => [true],
    'null' => [null],
    'array' => [[1]],
]);

it('rejects an allowed setting passed together with a forbidden one before sending', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => app(ReadOnlyClickHouseClient::class)->select('SELECT 1', [], [
        RoSettings::MAX_THREADS => 1, 'session_id' => 'x',
    ]))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('rejects non-string setting keys', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => app(ReadOnlyClickHouseClient::class)->select('SELECT 1', [], [0 => 1]))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('still validates allowed setting values', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => app(ReadOnlyClickHouseClient::class)->select('SELECT 1', [], [RoSettings::MAX_THREADS => [1]]))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('fails fast with a clear message when the read-only password is empty', function () {
    config(['clickhouse.readonly.password' => '']);
    app()->forgetInstance(ReadOnlyClickHouseClient::class);

    expect(fn () => app(ReadOnlyClickHouseClient::class))
        ->toThrow(RuntimeException::class, 'CLICKHOUSE_RO_PASSWORD');
});

it('fails fast on an empty password when constructed directly', function () {
    expect(fn () => new ReadOnlyClickHouseClient('http://ch:8123', 'posthog', 'posthog_ro', ''))
        ->toThrow(RuntimeException::class, 'CLICKHOUSE_RO_PASSWORD');
});

it('keeps the read-only config: fixed user, non-empty password with the compose default, timeout', function () {
    $config = require base_path('config/clickhouse.php');

    expect($config['readonly']['user'])->toBe('posthog_ro')
        ->and($config['readonly']['password'])->toBeString()->not->toBe('')
        ->and($config['readonly']['timeout'])->toBeInt()
        ->and(file_get_contents(base_path('config/clickhouse.php')))
        ->toContain("env('CLICKHOUSE_RO_PASSWORD', 'posthog_ro')")
        ->toContain("env('CLICKHOUSE_RO_TIMEOUT', 25)")
        ->not->toContain('CLICKHOUSE_RO_USER');
});
