<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
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
