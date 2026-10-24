<?php

use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const SCOPED_PREFIX = 'WITH project_events AS (SELECT * FROM posthog.events WHERE project_id = {project_id:UInt32}) ';

beforeEach(function () {
    $this->reader = new ProjectScopedReader(
        new ReadOnlyClickHouseClient('http://ch:8123', 'posthog', 'ro', 'pw'),
    );
});

/** @return array<string, mixed> */
function scopedQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

it('prepends the project_events CTE itself and sends the builder sql after it', function () {
    Http::fake(['*' => Http::response('{"data":[{"n":3}]}')]);

    $rows = $this->reader->select(42, 'SELECT count() AS n FROM project_events');

    expect($rows)->toBe([['n' => 3]]);
    Http::assertSent(fn (Request $r) => $r->body() === SCOPED_PREFIX.'SELECT count() AS n FROM project_events');
});

it('injects project_id as a bound param and as the SQL_project_id setting', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->reader->select(42, 'SELECT count() FROM project_events');

    Http::assertSent(function (Request $r) {
        $q = scopedQuery($r);

        return $q['param_project_id'] === '42' && $q['SQL_project_id'] === '42';
    });
});

it('accepts leading whitespace and any case for SELECT', function (string $sql) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->reader->select(1, $sql);

    Http::assertSent(fn (Request $r) => $r->body() === SCOPED_PREFIX.$sql);
})->with([
    'lower case' => 'select 1 FROM project_events',
    'leading spaces and newline' => "  \n\tSELECT 1 FROM project_events",
    'mixed case' => 'SeLeCt 1 FROM project_events',
]);

it('lets a builder use subqueries, UNION ALL, GROUP BY and typed params over project_events', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);
    $sql = 'SELECT event, count() AS n FROM (SELECT event FROM project_events WHERE event = {a:String} '
        .'UNION ALL SELECT event FROM project_events WHERE event = {b:String}) GROUP BY event LIMIT 10';

    $this->reader->select(7, $sql, ['a' => 'x', 'b' => 'y'], ['max_execution_time' => 5], 12);

    Http::assertSent(function (Request $r) use ($sql) {
        $q = scopedQuery($r);

        return $r->body() === SCOPED_PREFIX.$sql
            && $q['param_project_id'] === '7' && $q['param_a'] === 'x' && $q['param_b'] === 'y'
            && $q['max_execution_time'] === '5' && $q['SQL_project_id'] === '7';
    });
});

it('rejects builder sql that does not start with SELECT and sends nothing', function (string $sql) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(1, $sql))->toThrow(InvalidArgumentException::class, 'SELECT');
    Http::assertNothingSent();
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'with' => 'WITH x AS (SELECT 1) SELECT * FROM x',
    'insert' => 'INSERT INTO project_events VALUES (1)',
    'a comment first' => "\nSELECT_1",
    'paren' => '(SELECT 1)',
    'show' => 'SHOW TABLES',
]);

it('rejects bypass attempts in builder sql and sends nothing', function (string $sql, string $needle) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(1, $sql))->toThrow(InvalidArgumentException::class, $needle);
    Http::assertNothingSent();
})->with([
    'semicolon' => ['SELECT 1 FROM project_events; DROP TABLE x', ';'],
    'line comment' => ['SELECT 1 FROM project_events -- tail', '--'],
    'block comment start' => ['SELECT 1 /* x */ FROM project_events', '/*'],
    'block comment end' => ['SELECT 1 FROM project_events */', '*/'],
    'hash comment' => ['SELECT 1 FROM project_events # tail', '#'],
    'SETTINGS' => ['SELECT 1 FROM project_events SETTINGS SQL_project_id = 2', 'SETTINGS'],
    'lower case settings' => ['SELECT 1 FROM project_events settings max_threads = 1', 'SETTINGS'],
    'FORMAT' => ['SELECT 1 FROM project_events FORMAT TSV', 'FORMAT'],
    'INTO' => ["SELECT 1 FROM project_events INTO OUTFILE '/tmp/x'", 'INTO'],
    'posthog database' => ['SELECT * FROM posthog.events', 'posthog'],
    'posthog upper case' => ['SELECT * FROM POSTHOG.project_events', 'posthog'],
    'system database' => ['SELECT * FROM system.tables', 'system'],
    'bare events table' => ['SELECT * FROM events', 'events'],
    'bare events upper case' => ['SELECT * FROM EVENTS', 'events'],
    'events in a join' => ['SELECT 1 FROM project_events a JOIN events b ON a.uuid = b.uuid', 'events'],
    'events in a subquery' => ['SELECT 1 FROM project_events WHERE uuid IN (SELECT uuid FROM events)', 'events'],
    'project_id placeholder' => ['SELECT 1 FROM project_events WHERE project_id = {project_id:UInt32}', '{project_id'],
    'project_id placeholder with other type' => ['SELECT {project_id:UInt64}', '{project_id'],
    'backtick identifier' => ['SELECT 1 FROM `project_events`', 'backtick'],
    'quoted identifier' => ['SELECT 1 FROM "project_events"', 'double quote'],
]);

it('does not reject words that merely contain a forbidden word', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->reader->select(1, 'SELECT count() AS settings_total, formatDateTime(timestamp, {f:String}) AS f_into FROM project_events', ['f' => '%Y']);

    Http::assertSentCount(1);
});

it('rejects a caller param named project_id', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(1, 'SELECT 1 FROM project_events', ['project_id' => 2]))
        ->toThrow(InvalidArgumentException::class, 'project_id');
    Http::assertNothingSent();
});

it('rejects caller settings that start with SQL_ in any case', function (string $name) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(1, 'SELECT 1 FROM project_events', [], [$name => 2]))
        ->toThrow(InvalidArgumentException::class, 'SQL_');
    Http::assertNothingSent();
})->with(['SQL_project_id', 'SQL_other', 'sql_project_id', 'Sql_project_id']);

it('rejects non positive project ids', function (int $id) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select($id, 'SELECT 1 FROM project_events'))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([0, -1, PHP_INT_MIN]);

it('rejects a project id that does not fit UInt32', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(4294967296, 'SELECT 1 FROM project_events'))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('accepts the largest UInt32 project id', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->reader->select(4294967295, 'SELECT 1 FROM project_events');

    Http::assertSent(fn (Request $r) => scopedQuery($r)['SQL_project_id'] === '4294967295');
});

it('refuses a settings key that would override the client arguments', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(1, 'SELECT 1 FROM project_events', [], ['param_project_id' => 2]))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('resolves from the container on top of the read-only client, never the admin one', function () {
    $reader = app(ProjectScopedReader::class);
    $client = (new ReflectionProperty(ProjectScopedReader::class, 'client'))->getValue($reader);

    expect($client)->toBeInstanceOf(ReadOnlyClickHouseClient::class)
        ->and($client)->toBe(app(ReadOnlyClickHouseClient::class));
});

it('type-hints the ClickHouseReads interface and works with any implementation', function () {
    $fake = new class implements ClickHouseReads
    {
        public array $calls = [];

        public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
        {
            $this->calls[] = [$sql, $params, $settings, $timeout];

            return [['n' => 9]];
        }
    };

    $rows = (new ProjectScopedReader($fake))->select(5, 'SELECT 1 FROM project_events', ['a' => 1], ['max_threads' => 2], 9);

    expect($rows)->toBe([['n' => 9]])
        ->and($fake->calls)->toBe([[
            SCOPED_PREFIX.'SELECT 1 FROM project_events',
            ['project_id' => 5, 'a' => 1],
            ['max_threads' => 2, 'log_comment' => 'project:5', 'SQL_project_id' => 5],
            9,
        ]])
        ->and((string) (new ReflectionMethod(ProjectScopedReader::class, '__construct'))->getParameters()[0]->getType())
        ->toBe(ClickHouseReads::class);
});

it('sets log_comment to project:<id> automatically', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->reader->select(42, 'SELECT 1 FROM project_events');

    Http::assertSent(fn (Request $r) => scopedQuery($r)['log_comment'] === 'project:42');
});

it('keeps a caller supplied log_comment', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->reader->select(42, 'SELECT 1 FROM project_events', [], ['log_comment' => 'insight:trends']);

    Http::assertSent(fn (Request $r) => scopedQuery($r)['log_comment'] === 'insight:trends');
});

it('passes the per-call timeout to the client', function () {
    $seen = [];
    Http::fake(function ($request, $options) use (&$seen) {
        $seen[] = $options['timeout'] ?? null;

        return Http::response('{"data":[]}');
    });

    $this->reader->select(1, 'SELECT 1 FROM project_events', [], [], 11);
    $this->reader->select(1, 'SELECT 1 FROM project_events');

    expect($seen)->toBe([11, 25]);
});

it('refuses a setting outside the read-only allow-list', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(1, 'SELECT 1 FROM project_events', [], ['session_id' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'session_id');
    Http::assertNothingSent();
});
