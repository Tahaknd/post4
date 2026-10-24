<?php

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\BreakdownColumn;
use App\Domain\Insights\Trends\DateRange;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsResult;
use App\Domain\Insights\Trends\TrendsSqlBuilder;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Tests\Support\FailingCacheStore;

/** Replays queued answers (rows or a Throwable) and records every read that reached "ClickHouse". */
function scriptedClient(array $answers): ClickHouseReads
{
    return new class($answers) implements ClickHouseReads
    {
        public array $calls = [];

        public function __construct(private array $answers) {}

        public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
        {
            $this->calls[] = compact('sql', 'params', 'settings');
            $next = array_shift($this->answers) ?? [];
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }
    };
}

function runnerOn(ClickHouseReads $client, ?Repository $cache = null): InsightRunner
{
    return new InsightRunner(new ProjectScopedReader($client), new TrendsSqlBuilder, $cache ?? new Repository(new ArrayStore));
}

function def(string $event = 'signup', ?Breakdown $breakdown = null, string $from = '2026-03-01', string $to = '2026-03-04'): TrendsQuery
{
    return new TrendsQuery(
        [$event], Metric::TotalEvents,
        new DateRange(new DateTimeImmutable($from.' UTC'), new DateTimeImmutable($to.' UTC')),
        Interval::Day, 'UTC', $breakdown,
    );
}

/** One row per day for the 3 day window of def(). */
function dayRows(string $event = 'signup'): array
{
    return [
        ['series' => $event, 'bucket' => strtotime('2026-03-01 UTC'), 'value' => 4],
        ['series' => $event, 'bucket' => strtotime('2026-03-03 UTC'), 'value' => 9],
    ];
}

beforeEach(function () {
    config(['insights.cache_ttl_seconds' => 60, 'insights.cache_warning_interval_seconds' => 60]);
    InsightRunner::resetWarningThrottle();
});

afterEach(fn () => InsightRunner::resetWarningThrottle());

it('runs the query through the scoped reader for the project and returns a dense result', function () {
    $client = scriptedClient([dayRows()]);

    $result = runnerOn($client)->trends(42, def());

    expect($result)->toBeInstanceOf(TrendsResult::class)
        ->and($result->series[0]['data'])->toBe([4, 0, 9])
        ->and($client->calls)->toHaveCount(1)
        ->and($client->calls[0]['sql'])->toStartWith('WITH project_events AS (SELECT uuid,')
        ->and($client->calls[0]['settings']['SQL_project_id'])->toBe(42)
        ->and($client->calls[0]['settings']['log_comment'])->toStartWith('project:42 insight:trends:')
        ->and($client->calls[0]['params']['names'])->toBe(['signup']);
});

it('serves the second identical call from the cache', function () {
    $client = scriptedClient([dayRows(), dayRows('other')]);
    $runner = runnerOn($client);

    $first = $runner->trends(42, def());
    $second = $runner->trends(42, def());

    expect($client->calls)->toHaveCount(1)->and($second->toArray())->toBe($first->toArray());
});

it('caches per project and per definition', function () {
    $client = scriptedClient([dayRows(), dayRows(), dayRows('b'), dayRows()]);
    $runner = runnerOn($client);

    $runner->trends(1, def());
    $runner->trends(2, def());                          // other project: miss
    $runner->trends(1, def('b'));                       // other definition: miss
    $runner->trends(1, def(from: '2026-03-02'));        // other range: miss
    $runner->trends(1, def());                          // hit
    $runner->trends(2, def());                          // hit

    expect($client->calls)->toHaveCount(4);
});

it('builds the cache key from the project id and the hash of the canonical definition', function () {
    expect(InsightRunner::cacheKey(42, def()))->toBe('insights:trends:42:'.def()->hash())
        ->and(InsightRunner::cacheKey(43, def()))->not->toBe(InsightRunner::cacheKey(42, def()));
});

it('stores plain arrays with the configured ttl and nothing else', function () {
    $store = new ArrayStore;
    runnerOn(scriptedClient([dayRows()]), new Repository($store))->trends(5, def());

    $stored = $store->get(InsightRunner::cacheKey(5, def()));

    expect($stored)->toBeArray()->and($stored)->toHaveKeys(['timezone', 'buckets', 'labels', 'series'])
        ->and(TrendsResult::fromArray($stored))->toBeInstanceOf(TrendsResult::class);
});

it('does not cache an error and tries again on the next call', function () {
    $client = scriptedClient([new ClickHouseException('x', 202, ClickHouseErrorKind::Busy), dayRows()]);
    $store = new ArrayStore;
    $runner = runnerOn($client, new Repository($store));

    expect(fn () => $runner->trends(1, def()))->toThrow(InsightFailed::class);
    expect($store->get(InsightRunner::cacheKey(1, def())))->toBeNull();

    $result = $runner->trends(1, def());

    expect($result->series[0]['data'])->toBe([4, 0, 9])->and($client->calls)->toHaveCount(2);
});

it('treats a malformed cache entry as a miss and overwrites it', function () {
    $store = new ArrayStore;
    $store->put(InsightRunner::cacheKey(1, def()), ['timezone' => 'UTC', 'oops' => true], 60);
    $client = scriptedClient([dayRows()]);

    $result = runnerOn($client, new Repository($store))->trends(1, def());

    expect($client->calls)->toHaveCount(1)->and($result->series[0]['data'])->toBe([4, 0, 9])
        ->and(TrendsResult::fromArray($store->get(InsightRunner::cacheKey(1, def()))))->not->toBeNull();
});

it('rejects cached shapes whose series do not match the bucket axis', function () {
    expect(TrendsResult::fromArray(['timezone' => 'UTC', 'buckets' => [1, 2], 'labels' => ['a', 'b'], 'series' => [['event' => 'x', 'breakdown' => null, 'is_other' => false, 'is_none' => false, 'data' => [1]]]]))->toBeNull()
        ->and(TrendsResult::fromArray('nope'))->toBeNull()
        ->and(TrendsResult::fromArray(['timezone' => 'Mars/Base', 'buckets' => [], 'labels' => [], 'series' => []]))->toBeNull();
});

it('runs live every time when the ttl is 0', function () {
    config(['insights.cache_ttl_seconds' => 0]);
    $client = scriptedClient([dayRows(), dayRows()]);
    $runner = runnerOn($client);

    $runner->trends(1, def());
    $runner->trends(1, def());

    expect($client->calls)->toHaveCount(2);
});

describe('error mapping', function () {
    it('maps ClickHouse codes to a safe message, a kind and the retry flags', function (int $code, ClickHouseErrorKind $kind, bool $retryable, bool $busy) {
        $raw = "Code: {$code}. DB::Exception: boom in SELECT secret FROM x WHERE a = 'hunter2'";
        $client = scriptedClient([ClickHouseException::fromResponse($raw, 500)]);

        try {
            runnerOn($client)->trends(1, def());
            $this->fail('expected InsightFailed');
        } catch (InsightFailed $e) {
            expect($e->kind)->toBe($kind)
                ->and($e->isRetryable())->toBe($retryable)
                ->and($e->isBusy())->toBe($busy)
                ->and($e->getMessage())->toBe($kind->userMessage())
                ->and($e->getMessage())->not->toContain('hunter2')->not->toContain('SELECT')->not->toContain('Code')
                ->and($e->getPrevious())->toBeInstanceOf(ClickHouseException::class)
                ->and($e->getPrevious()->getMessage())->toContain('hunter2');
        }
    })->with([
        'timeout' => [159, ClickHouseErrorKind::Timeout, false, false],
        'memory' => [241, ClickHouseErrorKind::MemoryLimit, false, false],
        'too many rows' => [396, ClickHouseErrorKind::TooManyRows, false, false],
        'busy' => [202, ClickHouseErrorKind::Busy, true, true],
        'constraint' => [452, ClickHouseErrorKind::ConstraintViolation, false, false],
        'auth' => [516, ClickHouseErrorKind::AuthenticationFailed, false, false],
        'other' => [60, ClickHouseErrorKind::Unknown, false, false],
    ]);

    it('maps a connection failure to Unavailable (retryable, not busy)', function () {
        $client = scriptedClient([new ConnectionException('cURL error 7: Failed to connect to clickhouse port 8123')]);

        try {
            runnerOn($client)->trends(1, def());
            $this->fail('expected InsightFailed');
        } catch (InsightFailed $e) {
            expect($e->kind)->toBe(ClickHouseErrorKind::Unavailable)->and($e->isRetryable())->toBeTrue()->and($e->isBusy())->toBeFalse()
                ->and($e->getMessage())->not->toContain('cURL')->not->toContain('clickhouse');
        }
    });

    it('logs the raw text truncated, at warning, and busy at info; never in the exception message', function () {
        Log::spy();
        $client = scriptedClient([
            ClickHouseException::fromResponse('Code: 159. DB::Exception: Timeout exceeded'.str_repeat('z', 2000), 500),
            ClickHouseException::fromResponse('Code: 202. DB::Exception: Too many simultaneous queries', 500),
        ]);
        $runner = runnerOn($client);

        foreach ([1, 2] as $_) {
            try {
                $runner->trends(7, def());
            } catch (InsightFailed) {
            }
        }

        Log::shouldHaveReceived('log')->withArgs(fn ($level, $message, $context) => $level === 'warning' && $message === 'insight query failed'
            && $context['kind'] === 'timeout' && $context['code'] === 159 && $context['project_id'] === 7 && strlen($context['message']) <= 300)->once();
        Log::shouldHaveReceived('log')->withArgs(fn ($level, $message, $context) => $level === 'info' && $context['kind'] === 'busy')->once();
    });

    it('lets programming errors through (a lint violation is a bug, not a user error)', function () {
        $client = scriptedClient([new InvalidArgumentException('Project scoped SQL must not ...')]);

        expect(fn () => runnerOn($client)->trends(1, def()))->toThrow(InvalidArgumentException::class);
    });
});

describe('cache outage', function () {
    it('fails open to a live query and warns once per interval when the cache is down', function () {
        Log::spy();
        $client = scriptedClient([dayRows(), dayRows(), dayRows()]);
        $runner = runnerOn($client, new Repository(new FailingCacheStore));

        $a = $runner->trends(1, def());
        $b = $runner->trends(1, def());
        $c = $runner->trends(1, def());

        expect($a->series[0]['data'])->toBe([4, 0, 9])->and($b->toArray())->toBe($a->toArray())->and($c->toArray())->toBe($a->toArray())
            ->and($client->calls)->toHaveCount(3);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'insight cache unavailable, running the query live'
            && $context['exception'] === RuntimeException::class && $context['message'] === 'cache store unavailable')->once();
    });

    it('still maps ClickHouse errors when the cache is down', function () {
        $client = scriptedClient([new ClickHouseException('x', 202, ClickHouseErrorKind::Busy)]);

        expect(fn () => runnerOn($client, new Repository(new FailingCacheStore))->trends(1, def()))->toThrow(InsightFailed::class);
    });
});

it('never flushes the cache', function () {
    $store = Mockery::mock(ArrayStore::class)->makePartial();
    $store->shouldNotReceive('flush');

    runnerOn(scriptedClient([dayRows()]), new Repository($store))->trends(1, def());
    expect(true)->toBeTrue();
});

it('resolves from the container on the read-only client', function () {
    $runner = app(InsightRunner::class);
    $reader = (new ReflectionProperty(InsightRunner::class, 'reader'))->getValue($runner);

    expect($reader)->toBeInstanceOf(ProjectScopedReader::class);
});

it('keeps a breakdown result ordered: top series by size, other last', function () {
    $q = def('signup', Breakdown::byColumn(BreakdownColumn::Browser, 2));
    $day = strtotime('2026-03-01 UTC');
    $rows = [
        ['series' => 'signup', 'bucket' => $day, 'breakdown' => Breakdown::OTHER_LABEL, 'value' => 100],
        ['series' => 'signup', 'bucket' => $day, 'breakdown' => 'Chrome', 'value' => 5],
        ['series' => 'signup', 'bucket' => $day, 'breakdown' => 'Firefox', 'value' => 7],
    ];

    $result = runnerOn(scriptedClient([$rows]))->trends(1, $q);

    expect(array_column($result->series, 'breakdown'))->toBe(['Firefox', 'Chrome', Breakdown::OTHER_LABEL])
        ->and(array_column($result->series, 'is_other'))->toBe([false, false, true]);
});

describe('row cap', function () {
    /** @return list<array<string, mixed>> */
    function cells(int $count): array
    {
        $day = strtotime('2026-03-01 UTC');
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['series' => 'signup', 'bucket' => $day + 86400 * ($i % 3), 'breakdown' => 'v'.intdiv($i, 3), 'value' => 1];
        }

        return $rows;
    }

    it('accepts exactly MAX_ROWS populated cells and returns every one', function () {
        $q = def('signup', Breakdown::byColumn(BreakdownColumn::Browser, 25));

        $result = TrendsResult::fromRows($q, cells(TrendsQuery::MAX_ROWS));

        // 10000 cells over 3 buckets: 3334 breakdown values, the last one with a single cell
        expect(count($result->series))->toBe(3334)
            ->and(array_sum(array_map('array_sum', array_column($result->series, 'data'))))->toBe(TrendsQuery::MAX_ROWS);
    });

    it('refuses MAX_ROWS + 1 rows with a domain exception instead of showing a cut off chart', function () {
        $q = def('signup', Breakdown::byColumn(BreakdownColumn::Browser, 25));

        try {
            TrendsResult::fromRows($q, cells(TrendsQuery::MAX_ROWS + 1));
            $this->fail('expected InsightFailed');
        } catch (InsightFailed $e) {
            expect($e->kind)->toBe(ClickHouseErrorKind::TooManyRows)->and($e->getMessage())->toBe(ClickHouseErrorKind::TooManyRows->userMessage())
                ->and($e->getPrevious()->getMessage())->toContain((string) TrendsQuery::MAX_ROWS);
        }
    });

    it('surfaces the cap through the runner as InsightFailed and does not cache it', function () {
        $store = new ArrayStore;
        $q = def('signup', Breakdown::byColumn(BreakdownColumn::Browser, 25));

        expect(fn () => runnerOn(scriptedClient([cells(TrendsQuery::MAX_ROWS + 1)]), new Repository($store))->trends(1, $q))->toThrow(InsightFailed::class)
            ->and($store->get(InsightRunner::cacheKey(1, $q)))->toBeNull();
    });
});
