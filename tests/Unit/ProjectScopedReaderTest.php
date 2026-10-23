<?php

use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

it('injects project_id as a bound param and returns the rows', function () {
    Http::fake(['*' => Http::response('{"data":[{"n":3}]}')]);

    $rows = $this->reader->select(42, 'SELECT count() AS n FROM posthog.events WHERE project_id = {project_id:UInt32}');

    expect($rows)->toBe([['n' => 3]]);
    Http::assertSent(function (Request $r) {
        return scopedQuery($r)['param_project_id'] === '42'
            && $r->body() === 'SELECT count() AS n FROM posthog.events WHERE project_id = {project_id:UInt32}';
    });
});

it('passes caller params and settings along with the injected project_id', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->reader->select(
        7,
        'SELECT 1 FROM posthog.events WHERE project_id = {project_id:UInt32} AND event = {event:String}',
        ['event' => '$pageview'],
        ['max_execution_time' => 5],
    );

    Http::assertSent(function (Request $r) {
        $q = scopedQuery($r);

        return $q['param_project_id'] === '7'
            && $q['param_event'] === '$pageview'
            && $q['max_execution_time'] === '5';
    });
});

it('rejects sql without the project_id placeholder and sends nothing', function (string $sql) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(1, $sql))->toThrow(InvalidArgumentException::class, '{project_id:UInt32}');
    Http::assertNothingSent();
})->with([
    'no filter' => 'SELECT count() FROM posthog.events',
    'hard coded id' => 'SELECT count() FROM posthog.events WHERE project_id = 1',
    'other type' => 'SELECT count() FROM posthog.events WHERE project_id = {project_id:UInt64}',
    'other name' => 'SELECT count() FROM posthog.events WHERE project_id = {pid:UInt32}',
    'spaced' => 'SELECT count() FROM posthog.events WHERE project_id = { project_id : UInt32 }',
    'empty' => '',
]);

it('rejects a caller param named project_id', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(1, 'SELECT {project_id:UInt32}', ['project_id' => 2]))
        ->toThrow(InvalidArgumentException::class, 'project_id');
    Http::assertNothingSent();
});

it('rejects non positive project ids', function (int $id) {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select($id, 'SELECT {project_id:UInt32}'))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([0, -1, PHP_INT_MIN]);

it('rejects a project id that does not fit UInt32', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(4294967296, 'SELECT {project_id:UInt32}'))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('refuses a settings key that would override the client arguments', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(1, 'SELECT {project_id:UInt32}', [], ['param_project_id' => 2]))
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

    $rows = (new ProjectScopedReader($fake))->select(5, 'SELECT {project_id:UInt32}', ['a' => 1], ['max_threads' => 2]);

    expect($rows)->toBe([['n' => 9]])
        ->and($fake->calls)->toBe([['SELECT {project_id:UInt32}', ['project_id' => 5, 'a' => 1], ['max_threads' => 2, 'log_comment' => 'project:5'], null]])
        ->and((string) (new ReflectionMethod(ProjectScopedReader::class, '__construct'))->getParameters()[0]->getType())
        ->toBe(ClickHouseReads::class);
});

it('sets log_comment to project:<id> automatically', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->reader->select(42, 'SELECT {project_id:UInt32}');

    Http::assertSent(fn (Request $r) => scopedQuery($r)['log_comment'] === 'project:42');
});

it('keeps a caller supplied log_comment', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    $this->reader->select(42, 'SELECT {project_id:UInt32}', [], ['log_comment' => 'insight:trends']);

    Http::assertSent(fn (Request $r) => scopedQuery($r)['log_comment'] === 'insight:trends');
});

it('refuses a setting outside the read-only allow-list', function () {
    Http::fake(['*' => Http::response('{"data":[]}')]);

    expect(fn () => $this->reader->select(1, 'SELECT {project_id:UInt32}', [], ['session_id' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'session_id');
    Http::assertNothingSent();
});
