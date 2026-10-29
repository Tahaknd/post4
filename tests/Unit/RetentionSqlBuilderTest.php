<?php

use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\PropertyFilter;
use App\Domain\Insights\Retention\RetentionPeriod;
use App\Domain\Insights\Retention\RetentionSqlBuilder;
use App\Domain\Insights\Retention\RetentionType;
use App\Domain\Insights\Sql\PersonSource;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Tests\Support\RetentionFixtures as R;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

/** The SQL between the person source and the end, for the given cohort expression. */
function retentionSql(string $cohortPeriods, string $number, string $conditions = '(event = {target_event:String}) AS is_target, (event = {return_event:String}) AS is_return'): string
{
    return 'SELECT cohort, count() AS size, sumForEach(returned) AS retained FROM ('
        .'SELECT arrayJoin(cs) AS cohort, arrayMap(k -> has(rs, cohort + k), range(1, {periods:UInt8} + 1)) AS returned FROM ('
        ."SELECT {$cohortPeriods} AS cs, arrayDistinct(groupArrayIf(i, is_return)) AS rs FROM ("
        .'SELECT m_mapped, pk, i, is_target, is_return FROM ('
        .'SELECT m_mapped, if(m_mapped = 1, toString(m_person), distinct_id) AS pk, '
        ."toInt64({$number}(timestamp, {tz:String})) - toInt64({$number}({from:DateTime64(3)}, {tz:String})) AS i, "
        ."{$conditions} "
        .'FROM '.PersonSource::SQL.' WHERE timestamp >= {from:DateTime64(3)} AND timestamp < {until:DateTime64(3)} AND event IN {names:Array(String)}'
        .') WHERE is_target OR is_return'
        .') GROUP BY m_mapped, pk HAVING length(cs) > 0'
        .')) GROUP BY cohort ORDER BY cohort ASC LIMIT {cohorts:UInt64}';
}

describe('SQL snapshots', function () {
    it('builds the first time table of days', function () {
        $built = (new RetentionSqlBuilder)->build(R::query('signup', 'visit'));

        expect($built->sql)->toBe(retentionSql(
            'if(countIf(is_target) > 0, arrayFilter(x -> x < toInt64({cohorts:UInt64}), [minIf(i, is_target)]), [])',
            'toRelativeDayNum',
        ))->and(T::plain($built->params))->toBe([
            'from' => '2026-03-02 00:00:00.000',
            // 7 cohorts (03-02..03-08), 3 follow-up periods: read until the start of 03-12
            'until' => '2026-03-12 00:00:00.000',
            'tz' => 'UTC',
            'cohorts' => 7,
            'periods' => 3,
            'names' => ['signup', 'visit'],
            'target_event' => 'signup',
            'return_event' => 'visit',
        ]);
    });

    it('builds the recurring table of weeks', function () {
        $built = (new RetentionSqlBuilder)->build(R::query('signup', 'visit', ['type' => RetentionType::Recurring, 'period' => RetentionPeriod::Week, 'periods' => 5, 'from' => '2026-03-02', 'to' => '2026-03-23', 'zone' => 'Europe/Istanbul']));

        expect($built->sql)->toBe(retentionSql(
            'arrayDistinct(groupArrayIf(i, is_target AND i < toInt64({cohorts:UInt64})))',
            'toRelativeWeekNum',
        ))->and(T::plain($built->params))->toBe([
            // the Monday midnight of Istanbul (UTC+3) is 21:00 UTC the evening before
            'from' => '2026-03-01 21:00:00.000',
            // 3 weekly cohorts and 5 follow-up weeks
            'until' => '2026-04-26 21:00:00.000',
            'tz' => 'Europe/Istanbul',
            'cohorts' => 3,
            'periods' => 5,
            'names' => ['signup', 'visit'],
            'target_event' => 'signup',
            'return_event' => 'visit',
        ]);
    });

    it('numbers months with the month counter', function () {
        $built = (new RetentionSqlBuilder)->build(R::query(o: ['period' => RetentionPeriod::Month, 'from' => '2026-01-01', 'to' => '2026-04-01']));

        expect($built->sql)->toContain('toInt64(toRelativeMonthNum(timestamp, {tz:String})) - toInt64(toRelativeMonthNum({from:DateTime64(3)}, {tz:String}))');
    });

    it('lists one event name once and keeps one condition per role', function () {
        $built = (new RetentionSqlBuilder)->build(R::query('visit', 'visit'));

        expect($built->params['names'])->toBe(['visit'])
            ->and($built->params['target_event'])->toBe('visit')->and($built->params['return_event'])->toBe('visit');
    });

    it('binds the filters of each role with a prefix of its own', function () {
        $built = (new RetentionSqlBuilder)->build(R::query(
            ['signup', [new PropertyFilter('page', FilterOperator::Equals, '/pricing'), new PropertyFilter('ref', FilterOperator::IsSet)]],
            ['visit', [new PropertyFilter('plan', FilterOperator::Contains, 'pro')]],
        ));

        expect($built->sql)->toContain('(event = {target_event:String} AND JSONExtractString(properties, {target_f0_key:String}) = {target_f0_value:String} AND (JSONHas(properties, {target_f1_key:String}) AND JSONExtractRaw(properties, {target_f1_key:String}) != {target_f1_null:String})) AS is_target')
            ->and($built->sql)->toContain('(event = {return_event:String} AND positionCaseInsensitiveUTF8(JSONExtractString(properties, {return_f0_key:String}), {return_f0_value:String}) > 0) AS is_return')
            ->and($built->params)->toMatchArray(['target_f0_key' => 'page', 'target_f0_value' => '/pricing', 'target_f1_key' => 'ref', 'target_f1_null' => 'null', 'return_f0_key' => 'plan', 'return_f0_value' => 'pro']);
    });

    it('reads the person key from the shared person source, grouped with the mapped flag', function () {
        $sql = (new RetentionSqlBuilder)->build(R::query())->sql;

        expect($sql)->toContain(PersonSource::SQL)
            ->and($sql)->toContain(PersonSource::KEY.' AS pk')
            ->and($sql)->toContain('GROUP BY m_mapped, pk');
    });

    it('limits the rows to the number of cohorts, which the SQL can never exceed', function () {
        $built = (new RetentionSqlBuilder)->build(R::query(o: ['from' => '2026-03-02', 'to' => '2026-04-02']));

        expect($built->sql)->toEndWith('LIMIT {cohorts:UInt64}')->and($built->params['cohorts'])->toBe(31);
    });
});

describe('what reaches the reader', function () {
    it('passes the real reader lint for every shape and sends both relations', function (callable $make) {
        $fake = new ScriptedReads;
        $built = (new RetentionSqlBuilder)->build($make());

        (new ProjectScopedReader($fake))->select(7, $built->sql, $built->params);

        expect($fake->calls)->toHaveCount(1)
            ->and($fake->calls[0]['sql'])->toStartWith('WITH project_events AS (SELECT uuid,')
            ->and($fake->calls[0]['sql'])->toContain(', project_person_distinct_ids AS (SELECT project_id, distinct_id, person_id, version, created_at FROM posthog.project_person_distinct_ids_v) SELECT ')
            ->and($built->sql)->not->toContain("'")->not->toContain('$')->not->toContain('--')->not->toContain(';')->not->toContain('SETTINGS');
    })->with([
        'first time days' => [fn () => R::query()],
        'recurring weeks' => [fn () => R::query(o: ['type' => RetentionType::Recurring, 'period' => RetentionPeriod::Week])],
        'months' => [fn () => R::query('a', 'b', ['period' => RetentionPeriod::Month, 'periods' => 30, 'from' => '2025-03-01', 'to' => '2026-03-01'])],
        'other zone' => [fn () => R::query(o: ['zone' => 'America/Sao_Paulo'])],
        'all filter operators' => [fn () => R::query(
            ['a', [new PropertyFilter('k', FilterOperator::Equals, 'x'), new PropertyFilter('l', FilterOperator::NotEquals, 'x')]],
            ['b', [new PropertyFilter('m', FilterOperator::Contains, 'x'), new PropertyFilter('n', FilterOperator::IsSet)]],
            ['type' => RetentionType::Recurring],
        )],
    ]);

    it('builds the SAME sql text whatever the event names, keys, values, zone and dates are, so no value reaches the text', function () {
        $hostile = ["x' OR 1=1 --", 'a"b', 'a`b', "a\nb", 'a;b', '$$', 'a\\b', '%_', '{from:DateTime64(3)}', 'SETTINGS SQL_project_id=1', 'événement', ' '];
        $zones = ['UTC', 'Europe/Istanbul', 'America/New_York', 'Asia/Kolkata'];
        $texts = [];
        $params = [];
        foreach ($hostile as $n => $h) {
            $q = R::query(
                [$h.'1', [new PropertyFilter($h, FilterOperator::Equals, $h)]],
                [$h.'2', [new PropertyFilter($h.'k', FilterOperator::Contains, $h), new PropertyFilter($h.'z', FilterOperator::IsSet)]],
                ['from' => '2026-0'.(1 + strlen($h) % 8).'-01', 'to' => '2026-09-01', 'zone' => $zones[$n % 4], 'periods' => 1 + $n],
            );
            $built = (new RetentionSqlBuilder)->build($q);
            $texts[] = $built->sql;
            $params[] = json_encode(T::plain($built->params));
            (new ProjectScopedReader(new ScriptedReads))->select(7, $built->sql, $built->params);
        }

        expect(array_unique($texts))->toHaveCount(1)->and(array_unique($params))->toHaveCount(count($hostile));
    });

    it('refuses the query of another type', function () {
        expect(fn () => (new RetentionSqlBuilder)->build(T::query()))->toThrow(InvalidArgumentException::class)
            ->and(fn () => (new RetentionSqlBuilder)->result(T::query(), []))->toThrow(InvalidArgumentException::class);
    });
});
