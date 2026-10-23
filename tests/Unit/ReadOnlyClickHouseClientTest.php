<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'clickhouse.url' => 'http://ch:8123',
        'clickhouse.database' => 'posthog',
        'clickhouse.user' => 'admin_user',
        'clickhouse.password' => 'admin_pass',
        'clickhouse.readonly.user' => 'ro_user',
        'clickhouse.readonly.password' => 'ro_pass',
    ]);
    app()->forgetInstance(ClickHouseClient::class);
    app()->forgetInstance(ReadOnlyClickHouseClient::class);
});

function authorizationOf(Request $request): string
{
    return $request->header('Authorization')[0];
}

it('resolves the read-only subclass as its own shared singleton', function () {
    expect(app()->isShared(ReadOnlyClickHouseClient::class))->toBeTrue()
        ->and(app(ReadOnlyClickHouseClient::class))->toBeInstanceOf(ClickHouseClient::class)
        ->and(app(ReadOnlyClickHouseClient::class))->toBe(app(ReadOnlyClickHouseClient::class))
        ->and(app(ReadOnlyClickHouseClient::class))->not->toBe(app(ClickHouseClient::class));
});

it('connects with the read-only credentials, not the admin ones', function () {
    Http::fake(['*' => Http::response('{"data":[{"n":1}]}')]);

    $rows = app(ReadOnlyClickHouseClient::class)->select('SELECT 1 AS n');

    expect($rows)->toBe([['n' => 1]]);
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://ch:8123?')
        && str_contains($r->url(), 'database=posthog')
        && authorizationOf($r) === 'Basic '.base64_encode('ro_user:ro_pass'));
});

it('leaves the admin client on the admin credentials', function () {
    Http::fake(['*' => Http::response('')]);

    app(ClickHouseClient::class)->execute('CREATE TABLE a (x UInt8) ENGINE = Memory');

    expect(app(ClickHouseClient::class))->not->toBeInstanceOf(ReadOnlyClickHouseClient::class);
    Http::assertSent(fn (Request $r) => authorizationOf($r) === 'Basic '.base64_encode('admin_user:admin_pass'));
});

it('does not let the migrator resolve the read-only client', function () {
    $migrator = new ReflectionProperty(ClickHouseMigrator::class, 'clickhouse');

    $client = $migrator->getValue(app(ClickHouseMigrator::class));

    expect($client)->not->toBeInstanceOf(ReadOnlyClickHouseClient::class);
});

it('refuses to execute anything (reads only) and sends nothing', function () {
    Http::fake(['*' => Http::response('')]);

    expect(fn () => app(ReadOnlyClickHouseClient::class)->execute('INSERT INTO posthog.events VALUES (1)'))
        ->toThrow(LogicException::class);
    Http::assertNothingSent();
});

it('still sends per-query settings through select', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    app(ReadOnlyClickHouseClient::class)->select('SELECT 1', [], ['max_execution_time' => 5]);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'max_execution_time=5'));
});

it('reads the read-only credentials from config clickhouse.readonly with the posthog_ro default user', function () {
    $config = require base_path('config/clickhouse.php');

    expect($config['readonly'])->toHaveKeys(['user', 'password'])
        ->and(array_keys($config))->toContain('user', 'password', 'url', 'database', 'timeout', 'connect_timeout');
});
