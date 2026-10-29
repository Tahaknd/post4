<?php

use App\Domain\Insights\Funnel\FunnelOrder;
use App\Domain\Insights\Funnel\FunnelResult;
use App\Domain\Insights\Funnel\WindowUnit;
use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\InsightRunner;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\Support\FunnelFixtures as F;
use Tests\Support\ScriptedReads;

describe('result from the rows of the query', function () {
    it('turns people per level into people per step, with drop off and rates', function () {
        // 4 ended at step 1, 3 at step 2, 2 at step 3: 9 started, 5 got to step 2, 2 to step 3
        $result = FunnelResult::fromRows(F::query(['view', 'cart', 'buy']), F::levelRows([1 => 4, 2 => 3, 3 => 2]));

        expect(F::counts($result))->toBe([9, 5, 2])
            ->and(array_column($result->steps, 'event'))->toBe(['view', 'cart', 'buy'])
            ->and(array_column($result->steps, 'index'))->toBe([1, 2, 3])
            ->and(array_column($result->steps, 'dropped_off'))->toBe([0, 4, 3])
            ->and(array_column($result->steps, 'conversion_from_previous'))->toBe([null, 0.5556, 0.4])
            ->and(array_column($result->steps, 'conversion_from_first'))->toBe([1.0, 0.5556, 0.2222])
            ->and($result->entrants)->toBe(9)->and($result->converted)->toBe(2)->and($result->conversionRate)->toBe(0.2222)
            ->and($result->windowSeconds)->toBe(86400)->and($result->order)->toBe('ordered');
    });

    it('is complete when nobody starts: zeros and no rates, not zero rates', function () {
        $result = FunnelResult::fromRows(F::query(['a', 'b']), []);

        expect(F::counts($result))->toBe([0, 0])
            ->and($result->entrants)->toBe(0)->and($result->converted)->toBe(0)->and($result->conversionRate)->toBeNull()
            ->and(array_column($result->steps, 'conversion_from_first'))->toBe([null, null])
            ->and(array_column($result->steps, 'conversion_from_previous'))->toBe([null, null]);
    });

    it('reports a step nobody reached as a rate of 0 and the step after it as no rate', function () {
        $result = FunnelResult::fromRows(F::query(['a', 'b', 'c']), F::levelRows([1 => 5]));

        expect(F::counts($result))->toBe([5, 0, 0])
            ->and(array_column($result->steps, 'conversion_from_previous'))->toBe([null, 0.0, null])
            ->and(array_column($result->steps, 'conversion_from_first'))->toBe([1.0, 0.0, 0.0]);
    });

    it('accepts rows as strings (ClickHouse returns 64 bit integers as text) and in any order', function () {
        $rows = [['level' => '3', 'people' => '2'], ['level' => '1', 'people' => '4']];

        expect(F::counts(FunnelResult::fromRows(F::query(['a', 'b', 'c']), $rows)))->toBe([6, 2, 2]);
    });

    it('never shows a number built from rows outside its levels: it fails instead', function (array $rows) {
        expect(fn () => FunnelResult::fromRows(F::query(['a', 'b']), $rows))->toThrow(InsightFailed::class);
    })->with([
        'level 0' => [[['level' => 0, 'people' => 1]]],
        'level too deep' => [[['level' => 3, 'people' => 1]]],
        'negative persons' => [[['level' => 1, 'people' => -1]]],
        'no persons' => [[['level' => 1]]],
    ]);

    it('echoes the window and the order of the definition', function () {
        $result = FunnelResult::fromRows(F::query(['a', 'b'], ['window' => [90, WindowUnit::Minute], 'order' => FunnelOrder::StrictOrder]), []);

        expect($result->windowSeconds)->toBe(5400)->and($result->order)->toBe('strict_order');
    });
});

describe('result as a plain array', function () {
    it('survives the round trip through JSON, which is what the cache and the run record do', function () {
        $result = FunnelResult::fromRows(F::query(['a', 'b', 'c']), F::levelRows([1 => 4, 2 => 3, 3 => 2]));

        $again = FunnelResult::fromArray(json_decode(json_encode($result->toArray()), true));

        expect($again)->not->toBeNull()->and($again->toArray())->toBe($result->toArray());
    });

    it('treats an array of another shape as a cache miss', function (mixed $data) {
        expect(FunnelResult::fromArray($data))->toBeNull();
    })->with([
        'not an array' => ['x'],
        'null' => [null],
        'empty' => [[]],
        'steps empty' => [['steps' => [], 'entrants' => 0, 'converted' => 0, 'conversion_rate' => null, 'window_seconds' => 1, 'order' => 'ordered']],
        'unknown order' => [['steps' => [['index' => 1, 'event' => 'a', 'count' => 0, 'dropped_off' => 0, 'conversion_from_previous' => null, 'conversion_from_first' => null]], 'entrants' => 0, 'converted' => 0, 'conversion_rate' => null, 'window_seconds' => 1, 'order' => 'x']],
        'trends shaped' => [['timezone' => 'UTC', 'buckets' => [], 'labels' => [], 'series' => []]],
    ]);

    it('rejects a step list whose indexes or fields are off', function () {
        $good = FunnelResult::fromRows(F::query(['a', 'b']), F::levelRows([1 => 1]))->toArray();

        $wrongIndex = $good;
        $wrongIndex['steps'][1]['index'] = 5;
        $textCount = $good;
        $textCount['steps'][0]['count'] = '1';
        $noRate = $good;
        unset($noRate['steps'][0]['conversion_from_first']);

        expect(FunnelResult::fromArray($good))->not->toBeNull()
            ->and(FunnelResult::fromArray($wrongIndex))->toBeNull()
            ->and(FunnelResult::fromArray($textCount))->toBeNull()
            ->and(FunnelResult::fromArray($noRate))->toBeNull();
    });
});

describe('through the runner', function () {
    beforeEach(fn () => config(['insights.cache_ttl_seconds' => 60, 'insights.cache_warning_interval_seconds' => 60]));

    it('runs the funnel SQL, builds the result and serves the second call from the cache', function () {
        $client = new ScriptedReads([F::levelRows([1 => 3, 2 => 1])]);
        $store = new ArrayStore;
        $runner = F::runner($client, new Repository($store));
        $query = F::query(['a', 'b']);

        $first = $runner->funnel(42, $query);
        $second = $runner->funnel(42, $query);

        expect(F::counts($first))->toBe([4, 1])
            ->and($second->toArray())->toBe($first->toArray())
            ->and(count($client->calls))->toBe(1)
            ->and($client->calls[0]['settings']['log_comment'])->toBe('project:42 insight:funnel:'.substr($query->hash(), 0, 12))
            ->and($client->calls[0]['sql'])->toContain('windowFunnel(')
            ->and($runner->cached(42, $query)?->toArray())->toBe($first->toArray())
            ->and($runner->cached(43, $query))->toBeNull();
    });

    it('keys the cache by type, project and definition', function () {
        $query = F::query(['a', 'b']);

        expect(InsightRunner::cacheKey(42, $query))->toBe('insights:funnel:42:'.$query->hash())
            ->and(InsightRunner::cacheKey(42, $query))->not->toBe(InsightRunner::cacheKey(43, $query))
            ->and(InsightRunner::cacheKey(42, $query))->not->toBe(InsightRunner::cacheKey(42, F::query(['a', 'c'])));
    });

    it('never caches a failure and maps it to InsightFailed without server text', function () {
        $store = new ArrayStore;
        $client = new ScriptedReads([new ClickHouseException('Code: 241 SECRET-RAW', 241, ClickHouseErrorKind::MemoryLimit)]);
        $query = F::query(['a', 'b']);

        try {
            F::runner($client, new Repository($store))->funnel(1, $query);
            $this->fail('expected InsightFailed');
        } catch (InsightFailed $e) {
            expect($e->kind)->toBe(ClickHouseErrorKind::MemoryLimit)->and($e->getMessage())->not->toContain('SECRET-RAW');
        }
        expect($store->get(InsightRunner::cacheKey(1, $query)))->toBeNull();
    });

    it('treats a cached entry of another shape as a miss and runs the query', function () {
        $store = new ArrayStore;
        $query = F::query(['a', 'b']);
        $store->put(InsightRunner::cacheKey(1, $query), ['timezone' => 'UTC', 'oops' => true], 60);
        $client = new ScriptedReads([F::levelRows([1 => 2])]);

        $result = F::runner($client, new Repository($store))->funnel(1, $query);

        expect(F::counts($result))->toBe([2, 0])->and(count($client->calls))->toBe(1);
    });
});
