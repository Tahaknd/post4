<?php

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\BreakdownColumn;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsResult;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Support\FailingCacheStore;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

beforeEach(function () {
    config(['insights.cache_ttl_seconds' => 60, 'insights.cache_warning_interval_seconds' => 60]);
    InsightRunner::resetWarningThrottle();
});

afterEach(fn () => InsightRunner::resetWarningThrottle());

it('runs the query through the scoped reader for the project and returns a dense result', function () {
    $client = new ScriptedReads([T::dayRows()]);

    $result = T::runner($client)->trends(42, T::definition());

    expect($result)->toBeInstanceOf(TrendsResult::class)
        ->and($result->series[0]['data'])->toBe([4, 0, 9])
        ->and($client->calls)->toHaveCount(1)
        ->and($client->calls[0]['sql'])->toStartWith('WITH project_events AS (SELECT uuid,')
        ->and($client->calls[0]['settings']['SQL_project_id'])->toBe(42)
        ->and($client->calls[0]['settings']['log_comment'])->toStartWith('project:42 insight:trends:')
        ->and($client->calls[0]['params']['names'])->toBe(['signup']);
});

it('serves the second identical call from the cache', function () {
    $client = new ScriptedReads([T::dayRows(), T::dayRows('other')]);
    $runner = T::runner($client);

    $first = $runner->trends(42, T::definition());
    $second = $runner->trends(42, T::definition());

    expect($client->calls)->toHaveCount(1)->and($second->toArray())->toBe($first->toArray());
});

it('caches per project and per definition', function () {
    $client = new ScriptedReads([T::dayRows(), T::dayRows(), T::dayRows('b'), T::dayRows()]);
    $runner = T::runner($client);

    $runner->trends(1, T::definition());
    $runner->trends(2, T::definition());                          // other project: miss
    $runner->trends(1, T::definition('b'));                       // other definition: miss
    $runner->trends(1, T::definition(from: '2026-03-02'));        // other range: miss
    $runner->trends(1, T::definition());                          // hit
    $runner->trends(2, T::definition());                          // hit

    expect($client->calls)->toHaveCount(4);
});

it('builds the cache key from the project id and the hash of the canonical definition', function () {
    expect(InsightRunner::cacheKey(42, T::definition()))->toBe('insights:trends:42:'.T::definition()->hash())
        ->and(InsightRunner::cacheKey(43, T::definition()))->not->toBe(InsightRunner::cacheKey(42, T::definition()));
});

it('stores plain arrays with the configured ttl and nothing else', function () {
    $store = new ArrayStore;
    T::runner(new ScriptedReads([T::dayRows()]), new Repository($store))->trends(5, T::definition());

    $stored = $store->get(InsightRunner::cacheKey(5, T::definition()));

    expect($stored)->toBeArray()->and($stored)->toHaveKeys(['timezone', 'buckets', 'labels', 'series'])
        ->and(TrendsResult::fromArray($stored))->toBeInstanceOf(TrendsResult::class);
});

it('does not cache an error and tries again on the next call', function () {
    $client = new ScriptedReads([new ClickHouseException('x', 202, ClickHouseErrorKind::Busy), T::dayRows()]);
    $store = new ArrayStore;
    $runner = T::runner($client, new Repository($store));

    expect(fn () => $runner->trends(1, T::definition()))->toThrow(InsightFailed::class);
    expect($store->get(InsightRunner::cacheKey(1, T::definition())))->toBeNull();

    $result = $runner->trends(1, T::definition());

    expect($result->series[0]['data'])->toBe([4, 0, 9])->and($client->calls)->toHaveCount(2);
});

it('treats a malformed cache entry as a miss and overwrites it', function () {
    $store = new ArrayStore;
    $store->put(InsightRunner::cacheKey(1, T::definition()), ['timezone' => 'UTC', 'oops' => true], 60);
    $client = new ScriptedReads([T::dayRows()]);

    $result = T::runner($client, new Repository($store))->trends(1, T::definition());

    expect($client->calls)->toHaveCount(1)->and($result->series[0]['data'])->toBe([4, 0, 9])
        ->and(TrendsResult::fromArray($store->get(InsightRunner::cacheKey(1, T::definition()))))->not->toBeNull();
});

it('rejects cached shapes whose series do not match the bucket axis', function () {
    expect(TrendsResult::fromArray(['timezone' => 'UTC', 'buckets' => [1, 2], 'labels' => ['a', 'b'], 'series' => [['event' => 'x', 'breakdown' => null, 'is_other' => false, 'is_none' => false, 'data' => [1]]]]))->toBeNull()
        ->and(TrendsResult::fromArray('nope'))->toBeNull()
        ->and(TrendsResult::fromArray(['timezone' => 'Mars/Base', 'buckets' => [], 'labels' => [], 'series' => []]))->toBeNull();
});

it('runs live every time when the ttl is 0', function () {
    config(['insights.cache_ttl_seconds' => 0]);
    $client = new ScriptedReads([T::dayRows(), T::dayRows()]);
    $runner = T::runner($client);

    $runner->trends(1, T::definition());
    $runner->trends(1, T::definition());

    expect($client->calls)->toHaveCount(2);
});

describe('error mapping', function () {
    it('maps ClickHouse codes to a safe message, a kind and the retry flags', function (int $code, ClickHouseErrorKind $kind, bool $retryable, bool $busy) {
        $raw = "Code: {$code}. DB::Exception: boom in SELECT secret FROM x WHERE a = 'hunter2'";
        $client = new ScriptedReads([ClickHouseException::fromResponse($raw, 500)]);

        try {
            T::runner($client)->trends(1, T::definition());
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
        $client = new ScriptedReads([new ConnectionException('cURL error 7: Failed to connect to clickhouse port 8123')]);

        try {
            T::runner($client)->trends(1, T::definition());
            $this->fail('expected InsightFailed');
        } catch (InsightFailed $e) {
            expect($e->kind)->toBe(ClickHouseErrorKind::Unavailable)->and($e->isRetryable())->toBeTrue()->and($e->isBusy())->toBeFalse()
                ->and($e->getMessage())->not->toContain('cURL')->not->toContain('clickhouse');
        }
    });

    it('logs the raw text truncated, at warning, and busy at info; never in the exception message', function () {
        Log::spy();
        $client = new ScriptedReads([
            ClickHouseException::fromResponse('Code: 159. DB::Exception: Timeout exceeded'.str_repeat('z', 2000), 500),
            ClickHouseException::fromResponse('Code: 202. DB::Exception: Too many simultaneous queries', 500),
        ]);
        $runner = T::runner($client);

        foreach ([1, 2] as $_) {
            try {
                $runner->trends(7, T::definition());
            } catch (InsightFailed) {
            }
        }

        Log::shouldHaveReceived('log')->withArgs(fn ($level, $message, $context) => $level === 'warning' && $message === 'insight query failed'
            && $context['kind'] === 'timeout' && $context['code'] === 159 && $context['project_id'] === 7 && strlen($context['message']) <= 300)->once();
        Log::shouldHaveReceived('log')->withArgs(fn ($level, $message, $context) => $level === 'info' && $context['kind'] === 'busy')->once();
    });

    it('lets programming errors through (a lint violation is a bug, not a user error)', function () {
        $client = new ScriptedReads([new InvalidArgumentException('Project scoped SQL must not ...')]);

        expect(fn () => T::runner($client)->trends(1, T::definition()))->toThrow(InvalidArgumentException::class);
    });
});

describe('cache outage', function () {
    it('fails open to a live query and warns once per interval when the cache is down', function () {
        Log::spy();
        $client = new ScriptedReads([T::dayRows(), T::dayRows(), T::dayRows()]);
        $runner = T::runner($client, new Repository(new FailingCacheStore));

        $a = $runner->trends(1, T::definition());
        $b = $runner->trends(1, T::definition());
        $c = $runner->trends(1, T::definition());

        expect($a->series[0]['data'])->toBe([4, 0, 9])->and($b->toArray())->toBe($a->toArray())->and($c->toArray())->toBe($a->toArray())
            ->and($client->calls)->toHaveCount(3);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'insight cache unavailable, running the query live'
            && $context['exception'] === RuntimeException::class && $context['message'] === 'cache store unavailable')->once();
    });

    it('still maps ClickHouse errors when the cache is down', function () {
        $client = new ScriptedReads([new ClickHouseException('x', 202, ClickHouseErrorKind::Busy)]);

        expect(fn () => T::runner($client, new Repository(new FailingCacheStore))->trends(1, T::definition()))->toThrow(InsightFailed::class);
    });
});

it('never flushes the cache', function () {
    $store = new class extends ArrayStore
    {
        public int $flushes = 0;

        public function flush()
        {
            $this->flushes++;

            return parent::flush();
        }
    };

    $result = T::runner(new ScriptedReads([T::dayRows()]), new Repository($store))->trends(1, T::definition());

    // the result was cached (so the store was used) and flush() was never called
    expect($result->series[0]['data'])->toBe([4, 0, 9])
        ->and($store->get(InsightRunner::cacheKey(1, T::definition())))->toBeArray()
        ->and($store->flushes)->toBe(0);

    // and no CODE of the insight domain calls a flush (comments may explain why it must not)
    $calls = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain/Insights'), FilesystemIterator::SKIP_DOTS)) as $file) {
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'flush') {
                $calls[] = $file->getBasename();
            }
        }
    }
    expect($calls)->toBe([]);
});

it('resolves from the container on the read-only client', function () {
    $runner = app(InsightRunner::class);
    $reader = (new ReflectionProperty(InsightRunner::class, 'reader'))->getValue($runner);

    expect($reader)->toBeInstanceOf(ProjectScopedReader::class)
        // the container gives the runner config('insights.cache_store'), not the default store
        ->and((new ReflectionProperty(InsightRunner::class, 'cache'))->getValue($runner))->toBe(Cache::store((string) config('insights.cache_store')));
});

it('keeps a breakdown result ordered: top series by size, other last', function () {
    $q = T::definition('signup', Breakdown::byColumn(BreakdownColumn::Browser, 2));
    $day = strtotime('2026-03-01 UTC');
    $rows = [
        ['series' => 'signup', 'bucket' => $day, 'breakdown' => Breakdown::OTHER_LABEL, 'value' => 100],
        ['series' => 'signup', 'bucket' => $day, 'breakdown' => 'Chrome', 'value' => 5],
        ['series' => 'signup', 'bucket' => $day, 'breakdown' => 'Firefox', 'value' => 7],
    ];

    $result = T::runner(new ScriptedReads([$rows]))->trends(1, $q);

    expect(array_column($result->series, 'breakdown'))->toBe(['Firefox', 'Chrome', Breakdown::OTHER_LABEL])
        ->and(array_column($result->series, 'is_other'))->toBe([false, false, true]);
});

describe('row cap', function () {
    it('accepts exactly MAX_ROWS populated cells and returns every one', function () {
        $q = T::definition('signup', Breakdown::byColumn(BreakdownColumn::Browser, 25));

        $result = TrendsResult::fromRows($q, T::cells(TrendsQuery::MAX_ROWS));

        // 10000 cells over 3 buckets: 3334 breakdown values, the last one with a single cell
        expect(count($result->series))->toBe(3334)
            ->and(array_sum(array_map('array_sum', array_column($result->series, 'data'))))->toBe(TrendsQuery::MAX_ROWS);
    });

    it('refuses MAX_ROWS + 1 rows with a domain exception instead of showing a cut off chart', function () {
        $q = T::definition('signup', Breakdown::byColumn(BreakdownColumn::Browser, 25));

        try {
            TrendsResult::fromRows($q, T::cells(TrendsQuery::MAX_ROWS + 1));
            $this->fail('expected InsightFailed');
        } catch (InsightFailed $e) {
            expect($e->kind)->toBe(ClickHouseErrorKind::TooManyRows)->and($e->getMessage())->toBe(ClickHouseErrorKind::TooManyRows->userMessage())
                ->and($e->getPrevious()->getMessage())->toContain((string) TrendsQuery::MAX_ROWS);
        }
    });

    it('surfaces the cap through the runner as InsightFailed and does not cache it', function () {
        $store = new ArrayStore;
        $q = T::definition('signup', Breakdown::byColumn(BreakdownColumn::Browser, 25));

        expect(fn () => T::runner(new ScriptedReads([T::cells(TrendsQuery::MAX_ROWS + 1)]), new Repository($store))->trends(1, $q))->toThrow(InsightFailed::class)
            ->and($store->get(InsightRunner::cacheKey(1, $q)))->toBeNull();
    });
});

describe('cache store and outage cost', function () {
    it('skips the cache write when the read already failed (one timeout per outage, not two)', function () {
        $store = new class implements Store
        {
            public array $calls = [];

            public function get($key)
            {
                $this->calls[] = 'get';
                throw new RuntimeException('timeout');
            }

            public function put($key, $value, $seconds)
            {
                $this->calls[] = 'put';
                throw new RuntimeException('timeout');
            }

            public function many(array $keys) {}

            public function putMany(array $values, $seconds) {}

            public function increment($key, $value = 1) {}

            public function decrement($key, $value = 1) {}

            public function forever($key, $value) {}

            public function forget($key) {}

            public function flush() {}

            public function touch($key, $seconds) {}

            public function getPrefix()
            {
                return '';
            }
        };

        $result = T::runner(new ScriptedReads([T::dayRows()]), new Repository($store))->trends(1, T::definition());

        expect($result->series[0]['data'])->toBe([4, 0, 9])->and($store->calls)->toBe(['get']);
    });

    it('still writes when only the write can fail later (read worked)', function () {
        $store = new ArrayStore;

        T::runner(new ScriptedReads([T::dayRows()]), new Repository($store))->trends(1, T::definition());

        expect($store->get(InsightRunner::cacheKey(1, T::definition())))->toBeArray();
    });

    it('resolves the store from config insights.cache_store through the container', function () {
        config(['insights.cache_store' => 'array', 'cache.default' => 'file']);
        $client = new ScriptedReads([T::dayRows(), T::dayRows('x')]);
        $runner = app()->makeWith(InsightRunner::class, ['reader' => new ProjectScopedReader($client)]);

        $runner->trends(77, T::definition());
        $runner->trends(77, T::definition());

        expect($client->calls)->toHaveCount(1);
        Cache::store('array')->flush();
    });

    it('defaults to the redis store and reads the TTL from INSIGHTS_CACHE_TTL_SECONDS', function () {
        $config = require base_path('config/insights.php');

        expect(file_get_contents(base_path('config/insights.php')))->toContain("env('INSIGHTS_CACHE_STORE', 'redis')")->toContain("env('INSIGHTS_CACHE_TTL_SECONDS', 60)")
            ->and($config['cache_store'])->toBe('redis')
            ->and(file_get_contents(base_path('.env.example')))->toContain('INSIGHTS_CACHE_STORE')->toContain('INSIGHTS_CACHE_TTL_SECONDS');
    });
});

describe('failure levels and retry-after', function () {
    it('logs misconfiguration kinds at error, busy at info, the rest at warning', function (int $code, string $level) {
        Log::spy();
        $client = new ScriptedReads([ClickHouseException::fromResponse("Code: {$code}. DB::Exception: x", 500)]);

        try {
            T::runner($client)->trends(1, T::definition());
        } catch (InsightFailed) {
        }

        Log::shouldHaveReceived('log')->withArgs(fn ($l, $m) => $l === $level && $m === 'insight query failed')->once();
    })->with([
        'constraint' => [452, 'error'], 'auth' => [516, 'error'], 'busy' => [202, 'info'], 'timeout' => [159, 'warning'], 'memory' => [241, 'warning'], 'unknown' => [60, 'warning'],
    ]);

    it('offers a retry-after of 3 seconds for busy and none for the rest', function () {
        foreach (ClickHouseErrorKind::cases() as $kind) {
            expect((new InsightFailed($kind))->retryAfterSeconds())->toBe($kind === ClickHouseErrorKind::Busy ? 3 : null);
        }
    });
});

describe('axis versus the buckets ClickHouse returned', function () {
    it('uses the full computed axis when every returned bucket is on it', function () {
        $result = TrendsResult::fromRows(T::definition(), T::dayRows());

        expect($result->buckets)->toBe(T::definition()->buckets())->and($result->series[0]['data'])->toBe([4, 0, 9]);
    });

    it('uses the returned buckets as they are, inventing none and dropping none, when one is off the axis', function () {
        Log::spy();
        $day = strtotime('2026-03-01 UTC');
        $rows = [
            ['series' => 'signup', 'bucket' => $day + 1800, 'value' => 4],       // half hour off the daily grid
            ['series' => 'signup', 'bucket' => $day + 86400, 'value' => 9],      // on the grid
        ];

        $result = TrendsResult::fromRows(T::definition(), $rows);

        expect($result->buckets)->toBe([$day + 1800, $day + 86400])
            ->and($result->series[0]['data'])->toBe([4, 9])
            ->and(count($result->labels))->toBe(2);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => str_contains($m, 'differ from the computed axis') && $c['off_axis'] === 1)->once();
    });
});
