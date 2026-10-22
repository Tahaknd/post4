<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->client = new ClickHouseClient('http://ch:8123', 'posthog', 'u', 'p');
});

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

it('returns rows from select', function () {
    Http::fake(['*' => Http::response('{"data":[{"n":"1"}]}')]);

    $rows = $this->client->select('SELECT 1 AS n');

    expect($rows)->toBe([['n' => '1']]);
    Http::assertSent(fn ($r) => $r->body() === 'SELECT 1 AS n FORMAT JSON');
});

it('throws on clickhouse errors', function () {
    Http::fake(['*' => Http::response('Code: 62. Syntax error', 400)]);

    $this->client->execute('BAD SQL');
})->throws(RuntimeException::class, 'ClickHouse error');

it('carries no state from one call to the next', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->client->execute('INSERT INTO a SELECT {x:String}', ['x' => 'first']);
    $this->client->select('SELECT 2 AS n');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    /** @var Request $second */
    $second = $recorded[1][0];

    expect($second->url())->toBe('http://ch:8123?database=posthog')
        ->and($second->body())->toBe('SELECT 2 AS n FORMAT JSON')
        ->and($second->header('Authorization'))->toBe($recorded[0][0]->header('Authorization'))
        ->and($second->header('Content-Type'))->toBe(['text/plain']);
});
