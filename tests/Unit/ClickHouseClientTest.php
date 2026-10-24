<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->client = new ClickHouseClient('http://ch:8123', 'posthog', 'u', 'p');
});

/** @return array<string, mixed> Query-string arguments of a recorded request. */
function queryOf(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

/** Sends one param through execute() and returns the exact value that went over the wire. */
function sentParam(ClickHouseClient $client, mixed $value): string
{
    Http::fake(['*' => Http::response('')]);

    $client->execute('SELECT {p:String}', ['p' => $value]);

    $recorded = Http::recorded();

    return queryOf($recorded[count($recorded) - 1][0])['param_p'];
}

it('sends sql as body with bound params and credentials', function () {
    Http::fake(['*' => Http::response('')]);

    $this->client->execute('SELECT {id:UInt32}', ['id' => 7]);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_starts_with($request->url(), 'http://ch:8123?')
            && str_contains($request->url(), 'database=posthog')
            && str_contains($request->url(), 'param_id=7')
            && $request->body() === 'SELECT {id:UInt32}'
            && $request->hasHeader('Authorization');
    });
});

it('returns rows from select and sends the sql untouched', function () {
    Http::fake(['*' => Http::response('{"data":[{"n":"1"}]}')]);

    $rows = $this->client->select('SELECT 1 AS n;');

    expect($rows)->toBe([['n' => '1']]);
    Http::assertSent(function ($r) {
        $query = queryOf($r);

        return $r->body() === 'SELECT 1 AS n;'
            && $query['default_format'] === 'JSON'
            && $query['wait_end_of_query'] === '1';
    });
});

it('does not ask for json or buffering on execute', function () {
    Http::fake(['*' => Http::response('')]);

    $this->client->execute('CREATE TABLE a (x UInt8) ENGINE = Memory');

    Http::assertSent(function ($r) {
        $query = queryOf($r);

        return ! array_key_exists('default_format', $query)
            && ! array_key_exists('wait_end_of_query', $query)
            && $r->body() === 'CREATE TABLE a (x UInt8) ENGINE = Memory';
    });
});

it('throws on clickhouse errors', function () {
    Http::fake(['*' => Http::response('Code: 62. Syntax error', 400)]);

    $this->client->execute('BAD SQL');
})->throws(RuntimeException::class, 'ClickHouse error');

it('throws when a 200 response carries an exception', function () {
    Http::fake(['*' => Http::response('{"meta":[],"data":[],"rows":0,"exception":"Code: 395. DB::Exception: boom"}')]);

    $this->client->select('SELECT throwIf(1)');
})->throws(RuntimeException::class, 'ClickHouse error: {"meta":[],"data":[],"rows":0,"exception":"Code: 395');

it('throws when a select response has no data key', function () {
    Http::fake(['*' => Http::response('{"meta":[],"rows":0}')]);

    $this->client->select('SELECT 1');
})->throws(RuntimeException::class, 'ClickHouse error');

it('wraps an unparseable select response in a RuntimeException', function () {
    Http::fake(['*' => Http::response('this is not json')]);

    $this->client->select('SELECT 1');
})->throws(RuntimeException::class, 'ClickHouse error: unparseable response');

it('truncates response bodies in exception messages to 1000 characters', function () {
    Http::fake(['*' => Http::response(str_repeat('x', 5000), 500)]);

    try {
        $this->client->execute('BAD');
        $this->fail('expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('ClickHouse error: '.str_repeat('x', 1000));
    }
});

it('truncates the body of a 200 exception response too', function () {
    Http::fake(['*' => Http::response('{"exception":"'.str_repeat('y', 5000).'"}')]);

    try {
        $this->client->select('SELECT 1');
        $this->fail('expected an exception');
    } catch (RuntimeException $e) {
        expect(strlen($e->getMessage()))->toBe(strlen('ClickHouse error: ') + 1000);
    }
});

it('carries no state from one call to the next', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->client->execute('INSERT INTO a SELECT {x:String}', ['x' => 'first']);
    $this->client->select('SELECT 2 AS n');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    /** @var Request $second */
    $second = $recorded[1][0];

    expect($second->url())->toBe('http://ch:8123?database=posthog&default_format=JSON&wait_end_of_query=1')
        ->and($second->body())->toBe('SELECT 2 AS n')
        ->and($second->header('Authorization'))->toBe($recorded[0][0]->header('Authorization'))
        ->and($second->header('Content-Type'))->toBe(['text/plain']);
});

it('applies default timeouts per call', function () {
    $seen = [];
    Http::fake(function ($request, $options) use (&$seen) {
        $seen[] = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

        return Http::response('{"data":[]}');
    });

    $this->client->execute('SELECT 1');
    $this->client->select('SELECT 1');

    expect($seen)->toBe([[30, 10], [30, 10]]);
});

it('applies configured timeouts and a per-call execute override', function () {
    $seen = [];
    Http::fake(function ($request, $options) use (&$seen) {
        $seen[] = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

        return Http::response('');
    });

    $client = new ClickHouseClient('http://ch:8123', 'posthog', 'u', 'p', 5, 2);
    $client->execute('SELECT 1');
    $client->execute('OPTIMIZE TABLE a FINAL', timeout: 600);
    $client->execute('SELECT 1');

    expect($seen)->toBe([[5, 2], [600, 2], [5, 2]]);
});

it('encodes strings as escaped text', function () {
    expect(sentParam($this->client, 'plain'))->toBe('plain')
        ->and(sentParam($this->client, "a\nb"))->toBe('a\nb')
        ->and(sentParam($this->client, "t\tab"))->toBe('t\tab')
        ->and(sentParam($this->client, "cr\rhere"))->toBe('cr\rhere')
        ->and(sentParam($this->client, 'C:\new\temp'))->toBe('C:\\\\new\\\\temp')
        ->and(sentParam($this->client, 'it\'s "x" ünï'))->toBe('it\'s "x" ünï');
});

it('encodes booleans as 1 and 0', function () {
    expect(sentParam($this->client, true))->toBe('1')
        ->and(sentParam($this->client, false))->toBe('0');
});

it('encodes null as the TSV null marker', function () {
    expect(sentParam($this->client, null))->toBe('\N');
});

it('encodes ints and floats as plain numbers', function () {
    expect(sentParam($this->client, 42))->toBe('42')
        ->and(sentParam($this->client, -7))->toBe('-7')
        ->and(sentParam($this->client, 1.5))->toBe('1.5')
        ->and(sentParam($this->client, 0.1 + 0.2))->toBe('0.30000000000000004');
});

it('encodes string arrays as clickhouse array literals', function () {
    expect(sentParam($this->client, ['it\'s', 'y', 'x,y', 'back\slash']))
        ->toBe('[\'it\\\'s\',\'y\',\'x,y\',\'back\\\\slash\']')
        ->and(sentParam($this->client, []))->toBe('[]');
});

it('encodes numeric, boolean (true/false, as Array(Bool) requires), null and nested arrays', function () {
    expect(sentParam($this->client, [1, 2.5, true, false, null]))->toBe('[1,2.5,true,false,NULL]')
        ->and(sentParam($this->client, [[1, 2], ['a', 'b']]))->toBe('[[1,2],[\'a\',\'b\']]');
});

it('escapes control characters inside array strings', function () {
    expect(sentParam($this->client, ["new\nline", "t\tab"]))->toBe('[\'new\\nline\',\'t\\tab\']');
});

it('encodes datetimes in UTC with milliseconds', function () {
    $local = new DateTimeImmutable('2024-03-05 10:20:30.123456', new DateTimeZone('Europe/Istanbul'));

    expect(sentParam($this->client, $local))->toBe('2024-03-05 07:20:30.123')
        ->and(sentParam($this->client, new DateTime('2024-01-01 00:00:00', new DateTimeZone('UTC'))))
        ->toBe('2024-01-01 00:00:00.000');
});

it('does not mutate a datetime param', function () {
    $local = new DateTime('2024-03-05 10:20:30', new DateTimeZone('Europe/Istanbul'));

    sentParam($this->client, $local);

    expect($local->getTimezone()->getName())->toBe('Europe/Istanbul');
});

it('rejects unsupported param types', function () {
    $this->client->execute('SELECT {p:String}', ['p' => new stdClass]);
})->throws(InvalidArgumentException::class);

/** Sends one query-level settings array through select() (or execute()) and returns the URL arguments. */
function sentQuery(ClickHouseClient $client, array $settings, bool $viaSelect = true): array
{
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $viaSelect
        ? $client->select('SELECT 1', [], $settings)
        : $client->execute('SELECT 1', [], null, $settings);

    $recorded = Http::recorded();

    return queryOf($recorded[count($recorded) - 1][0]);
}

it('sends per-query settings as url arguments on select and execute', function () {
    foreach ([true, false] as $viaSelect) {
        $query = sentQuery($this->client, ['max_execution_time' => 5, 'result_overflow_mode' => 'throw'], $viaSelect);

        expect($query['max_execution_time'])->toBe('5')
            ->and($query['result_overflow_mode'])->toBe('throw')
            ->and($query['database'])->toBe('posthog');
    }
});

it('encodes bool, int, float and string settings', function () {
    $query = sentQuery($this->client, [
        'a_true' => true,
        'a_false' => false,
        'an_int' => 30,
        'a_zero' => 0,
        'a_float' => 0.5,
        'a_string' => 'a b&c=d',
    ]);

    expect($query['a_true'])->toBe('1')
        ->and($query['a_false'])->toBe('0')
        ->and($query['an_int'])->toBe('30')
        ->and($query['a_zero'])->toBe('0')
        ->and($query['a_float'])->toBe('0.5')
        ->and($query['a_string'])->toBe('a b&c=d');
});

it('keeps the client own arguments next to the settings', function () {
    $query = sentQuery($this->client, ['max_threads' => 2]);

    expect($query['default_format'])->toBe('JSON')
        ->and($query['wait_end_of_query'])->toBe('1')
        ->and($query['max_threads'])->toBe('2');
});

it('sends params and settings together without mixing them up', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->client->select('SELECT {id:UInt32}', ['id' => 7], ['max_execution_time' => 9]);

    $recorded = Http::recorded();
    $query = queryOf($recorded[0][0]);

    expect($query['param_id'])->toBe('7')
        ->and($query['max_execution_time'])->toBe('9')
        ->and(array_key_exists('id', $query))->toBeFalse();
});

it('does not carry settings over to the next call', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->client->select('SELECT 1', [], ['max_execution_time' => 9]);
    $this->client->select('SELECT 2');
    $this->client->execute('SELECT 3');

    $recorded = Http::recorded();

    expect(queryOf($recorded[0][0]))->toHaveKey('max_execution_time')
        ->and(queryOf($recorded[1][0]))->not->toHaveKey('max_execution_time')
        ->and(queryOf($recorded[2][0]))->not->toHaveKey('max_execution_time');
});

it('rejects invalid setting names', function (mixed $name) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->client->select('SELECT 1', [], [$name => 1]))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    'upper case' => 'Max_Time',
    'leading digit' => '1max',
    'leading underscore' => '_max',
    'dash' => 'max-time',
    'space' => 'max time',
    'ampersand' => 'a&b',
    'equals' => 'a=b',
    'brackets' => 'a[]',
    'empty' => '',
    'trailing newline' => "max_threads\n",
    'int key (list)' => 0,
]);

it('accepts custom settings with the SQL_ prefix and sends them as url arguments', function () {
    $query = sentQuery($this->client, ['SQL_project_id' => 42, 'SQL_other_1' => 'x']);

    expect($query['SQL_project_id'])->toBe('42')
        ->and($query['SQL_other_1'])->toBe('x')
        ->and($query['database'])->toBe('posthog');
});

it('rejects custom setting names that do not match SQL_ plus a lower case name', function (string $name) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->client->select('SELECT 1', [], [$name => 1]))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    'prefix only' => 'SQL_',
    'upper case name' => 'SQL_Project_id',
    'leading digit' => 'SQL_1a',
    'other prefix' => 'XSQL_a',
    'double prefix with dash' => 'SQL_a-b',
    'ampersand' => 'SQL_a&b',
    'space' => 'SQL_a b',
    'upper case prefix only partly' => 'Sql_a',
    'custom name with trailing newline' => "SQL_a\n",
]);

it('rejects non scalar setting values', function (mixed $value) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->client->select('SELECT 1', [], ['max_threads' => $value]))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    'null' => [null],
    'array' => [[1]],
    'object' => [new stdClass],
    'datetime' => [new DateTimeImmutable],
    'nan' => [NAN],
    'infinity' => [INF],
]);

it('rejects settings that collide with the client own url arguments', function (string $name) {
    Http::fake(['*' => Http::response('')]);

    expect(fn () => $this->client->execute('SELECT 1', [], null, [$name => 'x']))
        ->toThrow(InvalidArgumentException::class, $name);
    Http::assertNothingSent();
})->with(['database', 'default_format', 'wait_end_of_query', 'query', 'param_project_id', 'param_x']);

it('validates settings before anything is sent even when other settings are valid', function () {
    Http::fake(['*' => Http::response('')]);

    expect(fn () => $this->client->select('SELECT 1', [], ['max_threads' => 1, 'database' => 'other']))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('applies a per-call timeout override on select and keeps the default for the next call', function () {
    $seen = [];
    Http::fake(function ($request, $options) use (&$seen) {
        $seen[] = $options['timeout'] ?? null;

        return Http::response('{"data":[]}');
    });

    $client = new ClickHouseClient('http://ch:8123', 'posthog', 'u', 'p', 5, 2);
    $client->select('SELECT 1');
    $client->select('SELECT 2', timeout: 90);
    $client->select('SELECT 3');

    expect($seen)->toBe([5, 90, 5]);
});

it('implements the ClickHouseReads interface', function () {
    expect($this->client)->toBeInstanceOf(ClickHouseReads::class);
});

describe('select() retries', function () {
    beforeEach(function () {
        // 0 ms backoff keeps the tests fast; the doubling itself is not what is under test.
        $this->retrying = new ClickHouseClient('http://ch:8123', 'posthog', 'u', 'p', selectRetries: 2, selectRetryBackoffMs: 0);
    });

    it('retries a connection failure and returns the first good answer', function () {
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection('cURL error 7: Failed to connect to ch port 8123')
            ->pushFailedConnection('cURL error 7: Failed to connect to ch port 8123')
            ->push('{"data":[{"n":1}]}')]);

        expect($this->retrying->select('SELECT 1 AS n'))->toBe([['n' => 1]]);
        Http::assertSentCount(3);
    });

    it('gives up after the bounded number of attempts and rethrows the ConnectionException', function () {
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection('cURL error 7: refused')
            ->pushFailedConnection('cURL error 7: refused')
            ->pushFailedConnection('cURL error 7: refused')
            ->push('{"data":[]}')]);

        expect(fn () => $this->retrying->select('SELECT 1'))->toThrow(ConnectionException::class, 'refused');
        Http::assertSentCount(3);
    });

    it('does not retry a server error or a 200 exception', function (string $body, int $status) {
        Http::fake(['*' => Http::sequence()->push($body, $status)->push('{"data":[]}')]);

        expect(fn () => $this->retrying->select('SELECT 1'))->toThrow(ClickHouseException::class);
        Http::assertSentCount(1);
    })->with([
        'busy 5xx' => ['Code: 202. DB::Exception: Too many simultaneous queries. (TOO_MANY_SIMULTANEOUS_QUERIES)', 500],
        'timeout 200 exception' => ['{"meta":[],"data":[],"rows":0,"exception":"Code: 159. DB::Exception: Timeout exceeded. (TIMEOUT_EXCEEDED)"}', 200],
    ]);

    it('does not retry a client side timeout (cURL 28): the server may still be running the query', function () {
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out after 25001 milliseconds')
            ->push('{"data":[]}')]);

        expect(fn () => $this->retrying->select('SELECT 1'))->toThrow(ConnectionException::class, 'cURL error 28');
        Http::assertSentCount(1);
    });

    it('never retries execute()', function () {
        Http::fake(['*' => Http::sequence()->pushFailedConnection('cURL error 7: refused')->push('')]);

        expect(fn () => $this->retrying->execute('INSERT INTO t VALUES (1)'))->toThrow(ConnectionException::class);
        Http::assertSentCount(1);
    });

    it('does not retry by default', function () {
        Http::fake(['*' => Http::sequence()->pushFailedConnection('cURL error 7: refused')->push('{"data":[]}')]);

        expect(fn () => $this->client->select('SELECT 1'))->toThrow(ConnectionException::class);
        Http::assertSentCount(1);
    });

    it('sends the same query, params and settings on every attempt', function () {
        Http::fake(['*' => Http::sequence()->pushFailedConnection('cURL error 7: refused')->push('{"data":[]}')]);

        $this->retrying->select('SELECT {x:String}', ['x' => 'v'], ['max_threads' => 2]);

        $sent = array_map(fn (array $pair) => [$pair[0]->url(), $pair[0]->body()], Http::recorded()->all());
        expect($sent)->toHaveCount(2)->and($sent[0])->toBe($sent[1]);
    });

    it('doubles the pause between attempts', function () {
        Http::fake(['*' => Http::sequence()->pushFailedConnection('cURL error 7: refused')->pushFailedConnection('cURL error 7: refused')->push('{"data":[]}')]);
        $client = new ClickHouseClient('http://ch:8123', 'posthog', 'u', 'p', selectRetries: 2, selectRetryBackoffMs: 30);

        $start = hrtime(true);
        $client->select('SELECT 1');
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        // 30 ms + 60 ms of sleeping; generous upper bound for a loaded CI box
        expect($elapsedMs)->toBeGreaterThanOrEqual(85)->toBeLessThan(2000);
    });
});
