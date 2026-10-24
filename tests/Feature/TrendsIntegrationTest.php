<?php

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\BreakdownColumn;
use App\Domain\Insights\Trends\DateRange;
use App\Domain\Insights\Trends\FilterOperator;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\PropertyFilter;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsSqlBuilder;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\Support\ClickHouseIntegration as CH;
use Tests\Support\TrendsFixtures as T;

/*
 * TrendsQuery + InsightRunner against a real ClickHouse (25.8) through the real chain: read-only user, scoped
 * view, SQL_project_id. Opt-in (group "clickhouse"), run with `docker compose exec -T app composer test:clickhouse`.
 * The rows are fixed and hand-computed, see ClickHouseIntegration::seedTrends(): two tenants (A and B) with
 * overlapping distinct ids, a duplicate uuid, rows outside every range, and instants that fall on a different
 * day in UTC and in Europe/Istanbul. Every expectation below is an exact number, never "non empty".
 */

pest()->group('clickhouse');

beforeEach(function () {
    config(['insights.cache_ttl_seconds' => 0]);
    $this->ch = CH::connect();
    $this->ch->seedTrendsOnce();
    $this->runner = new InsightRunner($this->ch->reader, new TrendsSqlBuilder, new Repository(new ArrayStore));
});

afterAll(function () {
    CH::cleanupTrendsAfterAll();
});

it('counts events per UTC day for project A and B exactly, with zero buckets filled and no cross-tenant rows', function () {
    $q = T::integration(['events' => ['signup', 'purchase']]);

    $a = $this->runner->trends(CH::TREND_A, $q);
    $b = $this->runner->trends(CH::TREND_B, $q);
    $c = $this->runner->trends(CH::TREND_C, $q);

    // A: 7 signups (the duplicate uuid counts once), B: 7 signups of its own, C: nothing, same axis everywhere
    expect($a->buckets)->toBe($q->buckets())->and($a->buckets)->toHaveCount(4)
        ->and($b->buckets)->toBe($a->buckets)->and($c->buckets)->toBe($a->buckets)
        ->and(T::dataOf($a))->toBe(['signup' => [3, 3, 1, 0], 'purchase' => [0, 1, 1, 0]])
        ->and(T::dataOf($b))->toBe(['signup' => [3, 4, 0, 0], 'purchase' => [0, 1, 0, 0]])
        ->and(T::dataOf($c))->toBe(['signup' => [0, 0, 0, 0], 'purchase' => [0, 0, 0, 0]])
        ->and($a->labels[0])->toBe('2026-03-14T00:00:00+00:00');
});

it('counts unique distinct ids per bucket, not events, and per project (u1 exists in both tenants)', function () {
    $q = T::integration(['metric' => Metric::UniqueUsers]);

    expect(T::dataOf($this->runner->trends(CH::TREND_A, $q)))->toBe(['signup' => [2, 2, 1, 0]])   // u1,u2 | u1,u3 | u2
        ->and(T::dataOf($this->runner->trends(CH::TREND_B, $q)))->toBe(['signup' => [1, 1, 0, 0]]) // u9 | u1
        ->and(T::dataOf($this->runner->trends(CH::TREND_A, T::integration())))->toBe(['signup' => [3, 3, 1, 0]]);
});

it('deduplicates a repeated uuid for the total metric', function () {
    $q = T::integration(['range' => T::window('2026-03-16', '2026-03-17')]);

    // Two physical rows (or one after a merge), one event.
    expect(T::dataOf($this->runner->trends(CH::TREND_A, $q)))->toBe(['signup' => [1]]);
});

it('respects the date range: rows outside it never count and the end is exclusive', function () {
    // 02-10 and 04-20 are outside; a one-day window around 02-10 sees exactly that row
    expect(array_sum(T::dataOf($this->runner->trends(CH::TREND_A, T::integration()))['signup']))->toBe(7)
        ->and(T::dataOf($this->runner->trends(CH::TREND_A, T::integration(['range' => T::window('2026-02-10', '2026-02-11')])))['signup'])->toBe([1])
        ->and(T::dataOf($this->runner->trends(CH::TREND_A, T::integration(['range' => T::window('2026-02-11', '2026-02-12')])))['signup'])->toBe([0])
        // `to` is exclusive: the window ends exactly at 22:30:00.000, so the 22:30:00 row (a2) is NOT counted
        // and only a1 (21:30, bucket 21:00) is; the 22:00 bucket is empty
        ->and(T::dataOf($this->runner->trends(CH::TREND_A, T::integration(['range' => T::window('2026-03-14 21:00', '2026-03-14 22:30'), 'interval' => Interval::Hour])))['signup'])->toBe([1, 0])
        // `from` is inclusive
        ->and(T::dataOf($this->runner->trends(CH::TREND_A, T::integration(['range' => T::window('2026-03-14 22:30', '2026-03-14 22:31'), 'interval' => Interval::Hour])))['signup'])->toBe([1]);
});

it('puts instants on the project day: UTC and Europe/Istanbul disagree at the day boundary', function () {
    $istanbul = new DateTimeZone('Europe/Istanbul');
    $q = T::integration([
        'events' => ['signup', 'purchase'], 'timezone' => 'Europe/Istanbul',
        'range' => DateRange::localDays('2026-03-14', '2026-03-17', $istanbul),
    ]);

    $a = $this->runner->trends(CH::TREND_A, $q);

    // 03-14 21:30..23:00 UTC are 03-15 00:30..02:00 in Istanbul: local 03-14 is empty, local 03-15 has 6 signups
    expect($a->buckets)->toBe($q->buckets())
        ->and($a->labels)->toBe(['2026-03-14T00:00:00+03:00', '2026-03-15T00:00:00+03:00', '2026-03-16T00:00:00+03:00', '2026-03-17T00:00:00+03:00'])
        ->and(T::dataOf($a))->toBe(['signup' => [0, 6, 1, 0], 'purchase' => [0, 1, 0, 1]])   // purchase 03-16 23:30 UTC is local 03-17
        ->and(T::dataOf($this->runner->trends(CH::TREND_A, T::integration()))['signup'])->toBe([3, 3, 1, 0]);
});

it('aligns hourly buckets to the local hour in a half hour zone (Asia/Kolkata)', function () {
    $q = T::integration(['range' => T::window('2026-03-14 21:00', '2026-03-15 00:00'), 'interval' => Interval::Hour, 'timezone' => 'Asia/Kolkata']);

    $a = $this->runner->trends(CH::TREND_A, $q);

    // buckets start at :30 UTC; 21:30 -> 21:30, 22:30 and 23:00 -> 22:30
    expect(array_map(fn ($t) => gmdate('H:i', $t), $a->buckets))->toBe(['20:30', '21:30', '22:30', '23:30'])
        ->and($a->buckets)->toBe($q->buckets())
        ->and(T::dataOf($a))->toBe(['signup' => [0, 1, 2, 0]]);
});

it('starts weeks on Monday and months on the 1st in the project zone', function () {
    $week = T::integration(['range' => T::window('2026-03-09', '2026-03-23'), 'interval' => Interval::Week]);
    $weekUnique = T::integration(['range' => T::window('2026-03-09', '2026-03-23'), 'interval' => Interval::Week, 'metric' => Metric::UniqueUsers]);
    $istanbul = new DateTimeZone('Europe/Istanbul');
    $monthUtc = T::integration(['range' => T::window('2026-03-01', '2026-04-02'), 'interval' => Interval::Month]);
    $monthLocal = T::integration(['range' => DateRange::localDays('2026-03-01', '2026-04-01', $istanbul), 'interval' => Interval::Month, 'timezone' => 'Europe/Istanbul']);

    $w = $this->runner->trends(CH::TREND_A, $week);
    $mu = $this->runner->trends(CH::TREND_A, $monthUtc);
    $ml = $this->runner->trends(CH::TREND_A, $monthLocal);

    // 03-14 and 03-15 (Sat, Sun) fall in the week of Mon 03-09, 03-16 (Mon) opens the next one
    expect($w->buckets)->toBe($week->buckets())->and(T::dataOf($w))->toBe(['signup' => [6, 1]])
        ->and(T::dataOf($this->runner->trends(CH::TREND_A, $weekUnique)))->toBe(['signup' => [3, 1]])
        // 03-31 21:30 UTC is April 1st in Istanbul
        ->and($mu->buckets)->toBe($monthUtc->buckets())->and(T::dataOf($mu))->toBe(['signup' => [8, 0]])   // the window reaches into April
        ->and($ml->buckets)->toBe($monthLocal->buckets())->and($ml->labels)->toBe(['2026-03-01T00:00:00+03:00', '2026-04-01T00:00:00+03:00'])
        ->and(T::dataOf($ml))->toBe(['signup' => [7, 1]]);
});

it('breaks down by a property into the top N plus one merged other series', function () {
    $q1 = T::integration(['breakdown' => Breakdown::byProperty('plan', 1)]);
    $q3 = T::integration(['breakdown' => Breakdown::byProperty('plan', 3)]);
    $none = Breakdown::NONE_LABEL;
    $other = Breakdown::OTHER_LABEL;

    $r1 = $this->runner->trends(CH::TREND_A, $q1);
    $r3 = $this->runner->trends(CH::TREND_A, $q3);

    // plan over A's 7 signups: pro 3 (a1, a11, a3), free 2 (a2, a12), none 2 (a4 null, a6 absent)
    expect(T::dataOf($r1))->toBe(['signup|pro' => [1, 2, 0, 0], "signup|{$other}" => [2, 1, 1, 0]])
        ->and(array_column($r1->series, 'is_other'))->toBe([false, true])
        ->and(T::dataOf($r3))->toBe(['signup|pro' => [1, 2, 0, 0], "signup|{$none}" => [0, 1, 1, 0], 'signup|free' => [2, 0, 0, 0]])
        ->and(array_column($r3->series, 'is_none'))->toBe([false, true, false])
        // the totals never change with N: breakdown series sum to the plain series, bucket by bucket
        ->and(array_map('array_sum', array_map(null, ...array_column($r1->series, 'data'))))->toBe([3, 3, 1, 0])
        ->and(array_map('array_sum', array_map(null, ...array_column($r3->series, 'data'))))->toBe([3, 3, 1, 0]);
});

it('merges the values outside the top N BEFORE counting, so other is a union of users, not a sum', function () {
    $q = T::integration(['metric' => Metric::UniqueUsers, 'breakdown' => Breakdown::byProperty('plan', 1)]);

    $r = $this->runner->trends(CH::TREND_A, $q);

    // unique users per plan: free {u2,u1} = 2 and none {u3,u2} = 2 tie (the value order breaks the tie: the none
    // label sorts first), pro {u1} = 1. On 03-14 other = pro {u1} + free {u2,u1}: the UNION is 2 users, a sum of the
    // parts would say 3.
    expect(T::dataOf($r))->toBe([
        'signup|'.Breakdown::NONE_LABEL => [0, 1, 1, 0],
        'signup|'.Breakdown::OTHER_LABEL => [2, 1, 0, 0],
    ]);
});

it('breaks down by an allow listed column', function () {
    $r = $this->runner->trends(CH::TREND_A, T::integration(['breakdown' => Breakdown::byColumn(BreakdownColumn::Browser, 2)]));

    // browser over A's signups: Chrome 4 (a1, a12, a11, a3), Firefox 2 (a2, a6), Safari 1 (a4)
    expect(T::dataOf($r))->toBe(['signup|Chrome' => [2, 2, 0, 0], 'signup|Firefox' => [1, 0, 1, 0], 'signup|'.Breakdown::OTHER_LABEL => [0, 1, 0, 0]]);

    $b = $this->runner->trends(CH::TREND_B, T::integration(['breakdown' => Breakdown::byColumn(BreakdownColumn::Browser, 2)]));
    expect(T::dataOf($b))->toBe(['signup|Edge' => [3, 4, 0, 0]]);
});

it('keeps breakdown values of one tenant out of the other', function () {
    $q = T::integration(['breakdown' => Breakdown::byProperty('plan', 25)]);

    $a = array_column($this->runner->trends(CH::TREND_A, $q)->series, 'breakdown');
    $b = array_column($this->runner->trends(CH::TREND_B, $q)->series, 'breakdown');

    expect($a)->not->toContain('enterprise')->and($b)->toBe(['enterprise']);
});

it('filters by property: equals, not equals, contains, is set, and ANDs them', function () {
    $count = fn (array $filters) => array_sum(T::dataOf($this->runner->trends(CH::TREND_A, T::integration(['filters' => $filters])))['signup']);

    // over A's 7 signups
    expect($count([new PropertyFilter('plan', FilterOperator::Equals, 'pro')]))->toBe(3)
        ->and($count([new PropertyFilter('plan', FilterOperator::NotEquals, 'pro')]))->toBe(4)   // free x2, null, absent
        ->and($count([new PropertyFilter('plan', FilterOperator::Contains, 'RO')]))->toBe(3)     // case insensitive
        ->and($count([new PropertyFilter('plan', FilterOperator::IsSet)]))->toBe(5)              // pro x3 + free x2, null and absent are not set
        ->and($count([new PropertyFilter('plan', FilterOperator::Equals, 'pro'), new PropertyFilter('$browser', FilterOperator::Equals, 'Chrome')]))->toBe(3)
        ->and($count([new PropertyFilter('plan', FilterOperator::Equals, 'pro'), new PropertyFilter('$browser', FilterOperator::Equals, 'Firefox')]))->toBe(0)
        ->and($count([new PropertyFilter('missing_key', FilterOperator::IsSet)]))->toBe(0)
        ->and($count([new PropertyFilter('plan', FilterOperator::Equals, 'enterprise')]))->toBe(0);   // B's value, A sees nothing
});

it('treats hostile filter, breakdown and event values as plain data', function () {
    foreach (["x' OR '1'='1", "'; DROP TABLE posthog.events; --", '/* c */ SETTINGS SQL_project_id = 990202', '{p:UInt32} $$x$$', "back\\slash\n\ttab", '日本語 😀'] as $hostile) {
        $q = T::integration([
            'events' => [$hostile, 'signup'],
            'filters' => [new PropertyFilter($hostile, FilterOperator::Equals, $hostile), new PropertyFilter($hostile, FilterOperator::Contains, $hostile)],
        ]);
        $r = $this->runner->trends(CH::TREND_A, $q);

        // no error, no rows for the hostile event, and nothing of tenant B or other rows leaks in
        expect(T::dataOf($r))->toBe([$hostile => [0, 0, 0, 0], 'signup' => [0, 0, 0, 0]]);

        $withBreakdown = $this->runner->trends(CH::TREND_A, T::integration(['events' => [$hostile], 'breakdown' => Breakdown::byProperty($hostile)]));
        expect($withBreakdown->series)->toBe([]);
    }

    // the table is intact
    expect(array_sum(T::dataOf($this->runner->trends(CH::TREND_A, T::integration()))['signup']))->toBe(7);
});

it('cannot be pointed at another tenant by the definition: the project comes from the runner argument only', function () {
    // B's event names and values exist only in B
    $q = T::integration(['filters' => [new PropertyFilter('plan', FilterOperator::Equals, 'enterprise')]]);

    expect(array_sum(T::dataOf($this->runner->trends(CH::TREND_A, $q))['signup']))->toBe(0)
        ->and(array_sum(T::dataOf($this->runner->trends(CH::TREND_B, $q))['signup']))->toBe(7);
});

it('returns numbers as PHP ints through the real server (64 bit integers are not quoted)', function () {
    $built = (new TrendsSqlBuilder)->build(T::integration());

    $rows = $this->ch->reader->select(CH::TREND_A, $built->sql, $built->params);

    expect($rows)->not->toBeEmpty()
        ->and($rows[0]['value'])->toBeInt()
        ->and($rows[0]['bucket'])->toBeInt();
});

it('prunes the primary key for the generated query', function () {
    $built = (new TrendsSqlBuilder)->build(T::integration());

    $explain = $this->ch->ro->select(
        'EXPLAIN indexes = 1 '.ProjectScopedReader::cte().$built->sql,
        $built->params,
        ['SQL_project_id' => CH::TREND_A],
    );
    $text = implode("\n", array_column($explain, 'explain'));

    expect($text)->toContain('project_id in ['.CH::TREND_A.', '.CH::TREND_A.']');
});

it('maps a real server error to InsightFailed without leaking server text (wrong read-only password -> 516)', function () {
    $url = getenv('CLICKHOUSE_INTEGRATION_URL');
    $bad = new InsightRunner(
        new ProjectScopedReader(new ReadOnlyClickHouseClient($url, 'posthog', 'posthog_ro', 'definitely-wrong-password')),
        new TrendsSqlBuilder,
        new Repository(new ArrayStore),
    );

    try {
        $bad->trends(CH::TREND_A, T::integration());
        $this->fail('expected InsightFailed');
    } catch (InsightFailed $e) {
        expect($e->kind)->toBe(ClickHouseErrorKind::AuthenticationFailed)
            ->and($e->getMessage())->toBe(ClickHouseErrorKind::AuthenticationFailed->userMessage())
            ->and($e->getMessage())->not->toContain('posthog_ro')->not->toContain('Code')
            ->and($e->getPrevious()->getMessage())->toContain('516');
    }
});

it('maps a real memory or row limit error to a user message (result row limit -> 396)', function () {
    // A query the profile refuses at run time: more result rows than the per-query cap we pass (max_result_rows 1).
    try {
        $this->ch->reader->select(CH::TREND_A, 'SELECT uuid FROM project_events', [], ['max_result_rows' => 1]);
        $this->fail('expected a ClickHouseException');
    } catch (ClickHouseException $e) {
        expect($e->kind)->toBe(ClickHouseErrorKind::TooManyRows)->and($e->errorCode)->toBe(396)->and($e->userMessage())->not->toContain('Code');
    }
});

it('matches numeric and boolean property values with string filters (JSONExtractString returns their text on 25.8)', function () {
    $runner = $this->runner;
    $count = function (array $filters) use ($runner) {
        $q = T::query(['events' => ['typed'], 'range' => T::window('2023-09-05', '2023-09-06'), 'filters' => $filters]);

        return T::dataOf($runner->trends(CH::TREND_D, $q))['typed'][0];
    };

    // the row is {"n":5,"flag":true,"ratio":1.5,"nothing":null}
    expect($count([]))->toBe(1)
        ->and($count([new PropertyFilter('n', FilterOperator::Equals, '5')]))->toBe(1)
        ->and($count([new PropertyFilter('flag', FilterOperator::Equals, 'true')]))->toBe(1)
        ->and($count([new PropertyFilter('ratio', FilterOperator::Equals, '1.5')]))->toBe(1)
        ->and($count([new PropertyFilter('n', FilterOperator::Equals, '6')]))->toBe(0)
        // empty, null and absent are the same text: Equals '' matches them, NotEquals 'x' too, NotEquals '' does not
        ->and($count([new PropertyFilter('nothing', FilterOperator::Equals, '')]))->toBe(1)
        ->and($count([new PropertyFilter('absent', FilterOperator::Equals, '')]))->toBe(1)
        ->and($count([new PropertyFilter('absent', FilterOperator::NotEquals, 'x')]))->toBe(1)
        ->and($count([new PropertyFilter('nothing', FilterOperator::NotEquals, '')]))->toBe(0)
        ->and($count([new PropertyFilter('n', FilterOperator::NotEquals, '')]))->toBe(1)
        // is set: present and not null
        ->and($count([new PropertyFilter('n', FilterOperator::IsSet)]))->toBe(1)
        ->and($count([new PropertyFilter('nothing', FilterOperator::IsSet)]))->toBe(0);
});
