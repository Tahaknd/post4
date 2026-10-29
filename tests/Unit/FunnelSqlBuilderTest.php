<?php

use App\Domain\Insights\Funnel\FunnelOrder;
use App\Domain\Insights\Funnel\FunnelSqlBuilder;
use App\Domain\Insights\Funnel\WindowUnit;
use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\PropertyFilter;
use App\Domain\Insights\Sql\PersonSource;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Tests\Support\FunnelFixtures as F;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

describe('SQL snapshots', function () {
    it('builds the ordered two step funnel', function () {
        $built = (new FunnelSqlBuilder)->build(F::query(['signup', 'purchase']));

        expect($built->sql)->toBe(
            'SELECT level, count() AS people FROM ('
            .'SELECT windowFunnel({window:UInt64})(toUInt64(ms * 16 + 15 - step), step = 1 AND ms < toUInt64(toUnixTimestamp64Milli({to:DateTime64(3)})), step = 2) AS level '
            .'FROM (SELECT m_mapped, pk, ms, arrayJoin(hit) AS step '
            .'FROM (SELECT m_mapped, if(m_mapped = 1, toString(m_person), distinct_id) AS pk, toUInt64(toUnixTimestamp64Milli(timestamp)) AS ms, '
            .'arrayFilter(x -> x > 0, [if(event = {s0_event:String}, 1, 0), if(event = {s1_event:String}, 2, 0)]) AS hit '
            .'FROM '.PersonSource::SQL.' WHERE timestamp >= {from:DateTime64(3)} AND timestamp < {until:DateTime64(3)} AND event IN {names:Array(String)})) '
            .'GROUP BY m_mapped, pk) '
            .'WHERE level > 0 GROUP BY level ORDER BY level ASC LIMIT {levels:UInt8}'
        )->and(T::plain($built->params))->toBe([
            'from' => '2026-03-10 00:00:00.000',
            'to' => '2026-03-13 00:00:00.000',
            // 1 day after the end of the range
            'until' => '2026-03-14 00:00:00.000',
            // one day in milliseconds times the 16 ticks of the synthetic time
            'window' => 86400 * 1000 * 16,
            'levels' => 2,
            's0_event' => 'signup',
            's1_event' => 'purchase',
            'names' => ['signup', 'purchase'],
        ]);
    });

    it('reads every event for strict order and passes the mode as a parameter', function () {
        $built = (new FunnelSqlBuilder)->build(F::query(['a', 'b'], ['order' => FunnelOrder::StrictOrder]));

        expect($built->sql)->toContain('windowFunnel({window:UInt64}, {mode:String})(')
            ->and($built->sql)->toContain('arrayJoin(if(empty(hit), [0], hit)) AS step')
            ->and($built->sql)->not->toContain('event IN')
            ->and($built->sql)->not->toContain('{names:')
            ->and($built->params)->toMatchArray(['mode' => 'strict_order'])
            ->and($built->params)->not->toHaveKey('names');
    });

    it('keeps the plain mode free of any mode parameter and filters the events by name', function () {
        $built = (new FunnelSqlBuilder)->build(F::query(['a', 'b']));

        expect($built->sql)->toContain('windowFunnel({window:UInt64})(')->and($built->sql)->not->toContain('{mode:')
            ->and($built->params)->not->toHaveKey('mode');
    });

    it('names each step by its position and binds its filters with a prefix of their own', function () {
        $built = (new FunnelSqlBuilder)->build(F::query([
            ['view', [new PropertyFilter('page', FilterOperator::Equals, '/pricing'), new PropertyFilter('ref', FilterOperator::IsSet)]],
            'cart',
            ['buy', [new PropertyFilter('plan', FilterOperator::Contains, 'pro')]],
        ]));

        expect($built->sql)->toContain('if(event = {s0_event:String} AND JSONExtractString(properties, {s0_f0_key:String}) = {s0_f0_value:String} AND (JSONHas(properties, {s0_f1_key:String}) AND JSONExtractRaw(properties, {s0_f1_key:String}) != {s0_f1_null:String}), 1, 0)')
            ->and($built->sql)->toContain('if(event = {s1_event:String}, 2, 0)')
            ->and($built->sql)->toContain('if(event = {s2_event:String} AND positionCaseInsensitiveUTF8(JSONExtractString(properties, {s2_f0_key:String}), {s2_f0_value:String}) > 0, 3, 0)')
            ->and($built->sql)->toContain('step = 1 AND ms < toUInt64(toUnixTimestamp64Milli({to:DateTime64(3)})), step = 2, step = 3) AS level')
            ->and($built->params)->toMatchArray(['s0_f0_key' => 'page', 's0_f0_value' => '/pricing', 's0_f1_key' => 'ref', 's0_f1_null' => 'null', 's2_f0_key' => 'plan', 's2_f0_value' => 'pro', 'levels' => 3])
            ->and($built->params['names'])->toBe(['view', 'cart', 'buy']);
    });

    it('lists a repeated event name once in the names and keeps one condition per step', function () {
        $built = (new FunnelSqlBuilder)->build(F::query(['view', 'view', 'buy']));

        expect($built->params['names'])->toBe(['view', 'buy'])
            ->and($built->params['s0_event'])->toBe('view')->and($built->params['s1_event'])->toBe('view')
            ->and($built->params['levels'])->toBe(3);
    });

    it('reads the window past the end of the range, in ticks', function () {
        $built = (new FunnelSqlBuilder)->build(F::query(['a', 'b'], ['from' => '2026-03-01', 'to' => '2026-03-08', 'window' => [90, WindowUnit::Minute]]));

        expect(T::plain($built->params))->toMatchArray([
            'to' => '2026-03-08 00:00:00.000',
            'until' => '2026-03-08 01:30:00.000',
            'window' => 90 * 60 * 1000 * 16,
        ]);
    });

    it('groups people by the person key together with the mapped flag, from the shared person source', function () {
        $sql = (new FunnelSqlBuilder)->build(F::query(['a', 'b']))->sql;

        expect($sql)->toContain(PersonSource::SQL)
            ->and($sql)->toContain(PersonSource::KEY.' AS pk')
            ->and($sql)->toContain('GROUP BY m_mapped, pk');
    });
});

describe('what reaches the reader', function () {
    it('passes the real reader lint for every shape and sends both relations', function (callable $make) {
        $fake = new ScriptedReads;
        $built = (new FunnelSqlBuilder)->build($make());

        (new ProjectScopedReader($fake))->select(7, $built->sql, $built->params);

        expect($fake->calls)->toHaveCount(1)
            ->and($fake->calls[0]['sql'])->toStartWith('WITH project_events AS (SELECT uuid,')
            ->and($fake->calls[0]['sql'])->toContain(', project_person_distinct_ids AS (SELECT project_id, distinct_id, person_id, version, created_at FROM posthog.project_person_distinct_ids_v) SELECT ')
            ->and($built->sql)->not->toContain("'")->not->toContain('$')->not->toContain('--')->not->toContain(';')->not->toContain('SETTINGS');
    })->with([
        'two steps' => [fn () => F::query(['a', 'b'])],
        'eight steps' => [fn () => F::query(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'])],
        'strict order' => [fn () => F::query(['a', 'b', 'c'], ['order' => FunnelOrder::StrictOrder])],
        'repeated event' => [fn () => F::query(['a', 'a', 'b'])],
        'all filter operators' => [fn () => F::query([
            ['a', [new PropertyFilter('k', FilterOperator::Equals, 'x'), new PropertyFilter('l', FilterOperator::NotEquals, 'x')]],
            ['b', [new PropertyFilter('m', FilterOperator::Contains, 'x'), new PropertyFilter('n', FilterOperator::IsSet)]],
        ], ['order' => FunnelOrder::StrictOrder])],
    ]);

    it('builds the SAME sql text whatever the event names, keys, values and dates are, so no value reaches the text', function () {
        $hostile = ["x' OR 1=1 --", 'a"b', 'a`b', "a\nb", 'a;b', '$$', 'a\\b', '%_', '{from:DateTime64(3)}', 'SETTINGS SQL_project_id=1', 'événement', ' '];
        $texts = [];
        $params = [];
        foreach ($hostile as $h) {
            $q = F::query([
                [$h.'1', [new PropertyFilter($h, FilterOperator::Equals, $h)]],
                [$h.'2', [new PropertyFilter($h.'k', FilterOperator::Contains, $h), new PropertyFilter($h.'z', FilterOperator::IsSet)]],
            ], ['from' => '2026-0'.(1 + strlen($h) % 8).'-01', 'to' => '2026-09-01']);
            $built = (new FunnelSqlBuilder)->build($q);
            $texts[] = $built->sql;
            $params[] = json_encode(T::plain($built->params));
            // and through the real lint
            (new ProjectScopedReader(new ScriptedReads))->select(7, $built->sql, $built->params);
        }

        expect(array_unique($texts))->toHaveCount(1)->and(array_unique($params))->toHaveCount(count($hostile));
    });

    it('refuses the query of another type', function () {
        expect(fn () => (new FunnelSqlBuilder)->build(T::query()))->toThrow(InvalidArgumentException::class)
            ->and(fn () => (new FunnelSqlBuilder)->result(T::query(), []))->toThrow(InvalidArgumentException::class);
    });
});
