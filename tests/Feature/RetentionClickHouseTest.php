<?php

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\PropertyFilter;
use App\Domain\Insights\Retention\RetentionPeriod;
use App\Domain\Insights\Retention\RetentionQuery;
use App\Domain\Insights\Retention\RetentionResult;
use App\Domain\Insights\Retention\RetentionType;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient;
use App\Models\Project;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ClickHouseIntegration as CH;
use Tests\Support\RetentionFixtures as R;

/*
 * Retention tables against a real ClickHouse 25.8, through the real chain: read-only user, the scoped views, SQL_project_id,
 * InsightRunner. Opt-in (group "clickhouse"). Fixed instants and hand-computed numbers: every expectation is an exact
 * table. Every scenario has an event name (or distinct ids) of its own, so scenarios never touch each other's counts.
 * The clock is fixed at 2026-06-01, after all the data, unless a test says otherwise. Days are `d02` = 2026-03-02.
 *
 * Tables are written `[size, [retained in period 1, 2, ...]]` per cohort (a retained count of null: the period has not
 * started). The default query: daily periods, UTC, range 2026-03-02..2026-03-05 (EXCLUSIVE: the cohorts of 03-02, 03-03, 03-04),
 * 3 follow-up periods, so events are read from 03-02 00:00 up to 03-08 00:00 (exclusive).
 *
 * S1 vis1 (target = return), who is active on which day:
 *      p1  02 03 05          p2  02 04            p3  03              p4  02 23:59:59.999, 03 00:00:00.000
 *      p5  04 04 04 05 00:00:00.000, 07           p6  01 (before the first period: not read), 03
 *      p7  05 only (no cohort: its first period is after the cohorts)   p8  04, 08 00:00:00.000 (the end of the window: not read)
 *   first time: C0 = p1 p2 p4, C1 = p3 p6, C2 = p5 p8        recurring: p1 and p4 are in C0 and C1, p2 and p5.. in C0/C2
 */

pest()->group('clickhouse');

/** @return array{0: int, 1: string, 2: string, 3: string, 4: string} an insertRows row */
function rev(int $project, string $event, string $distinct, string $ts, string $props = '{}'): array
{
    return [$project, $event, $distinct, $props, $ts];
}

/** The default query of this file: the cohorts of 03-02, 03-03 and 03-04 (the range ends at the start of 03-05). */
function rq(string|array $target, string|array $returning, array $o = []): RetentionQuery
{
    return R::query($target, $returning, $o + ['from' => '2026-03-02', 'to' => '2026-03-05']);
}

/** Person uuids that are easy to read in a failure. */
function rpp(int $n): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $n);
}

/** Project A's, B's events and mappings: every scenario, once per process. */
function seedRetentionProjects(CH $ch): void
{
    static $seeded = false;
    if ($seeded) {
        return;
    }
    $ch->deletePersonRowsOf(CH::RET_IDS);
    $ch->deleteRowsOf(CH::RET_IDS);
    $a = CH::RET_A;
    $b = CH::RET_B;
    $rows = [];
    /** One row per timestamp given. */
    $add = function (int $project, string $event, string $distinct, string ...$times) use (&$rows): void {
        foreach ($times as $t) {
            $rows[] = rev($project, $event, $distinct, $t);
        }
    };
    $withProps = function (int $project, string $event, string $distinct, string $time, string $props) use (&$rows): void {
        $rows[] = rev($project, $event, $distinct, $time, $props);
    };

    // S1 vis1
    $add($a, 'vis1', 'p1', '2026-03-02 10:00:00', '2026-03-03 11:00:00', '2026-03-05 09:00:00');
    $add($a, 'vis1', 'p2', '2026-03-02 12:00:00', '2026-03-04 08:00:00');
    $add($a, 'vis1', 'p3', '2026-03-03 09:00:00');
    $add($a, 'vis1', 'p4', '2026-03-02 23:59:59.999', '2026-03-03 00:00:00.000');
    $add($a, 'vis1', 'p5', '2026-03-04 10:00:00', '2026-03-04 11:00:00', '2026-03-04 12:00:00', '2026-03-05 00:00:00.000', '2026-03-07 12:00:00');
    $add($a, 'vis1', 'p6', '2026-03-01 12:00:00', '2026-03-03 10:00:00');
    $add($a, 'vis1', 'p7', '2026-03-05 10:00:00');
    $add($a, 'vis1', 'p8', '2026-03-04 10:00:00', '2026-03-08 00:00:00.000');

    // S2 sig2 / buy2 (two different events)
    $add($a, 'sig2', 'q1', '2026-03-02 09:00:00');
    $add($a, 'buy2', 'q1', '2026-03-02 10:00:00', '2026-03-04 10:00:00');
    $add($a, 'sig2', 'q2', '2026-03-02 09:00:00');
    $add($a, 'buy2', 'q2', '2026-03-03 08:00:00', '2026-03-03 20:00:00');
    $add($a, 'sig2', 'q3', '2026-03-03 09:00:00');
    $add($a, 'buy2', 'q3', '2026-03-02 09:00:00');
    $add($a, 'sig2', 'q4', '2026-03-02 15:00:00', '2026-03-04 15:00:00');
    $add($a, 'buy2', 'q4', '2026-03-05 12:00:00');
    $add($a, 'buy2', 'q5', '2026-03-03 12:00:00');

    // S3 wk: ISO weeks W0 03-02, W1 03-09, W2 03-16, W3 03-23
    $add($a, 'wk', 'w1', '2026-03-08 23:59:59.999', '2026-03-09 00:00:00.000', '2026-03-22 23:59:59.999');
    $add($a, 'wk', 'w2', '2026-03-02 00:00:00.000', '2026-03-15 23:59:59.000', '2026-03-16 00:00:00.000');
    $add($a, 'wk', 'w3', '2026-03-01 12:00:00', '2026-03-23 00:00:00.000');
    $add($a, 'wk', 'w4', '2026-03-09 10:00:00', '2026-03-23 10:00:00');
    $add($a, 'wk', 'w5', '2026-03-16 10:00:00');

    // S4 mo: months
    $add($a, 'mo', 'm1', '2026-01-31 23:59:59.999', '2026-02-01 00:00:00.000', '2026-04-01 00:00:00.000');
    $add($a, 'mo', 'm2', '2026-01-01 00:00:00.000', '2026-03-31 23:59:59.999', '2026-05-31 23:59:59.999');
    $add($a, 'mo', 'm3', '2025-12-31 23:59:59.000', '2026-02-28 12:00:00', '2026-04-30 12:00:00');
    $add($a, 'mo', 'm4', '2026-06-01 00:00:00.000');

    // S5 tz: the local day of Istanbul (UTC+3) starts at 21:00 UTC
    $add($a, 'tz', 't1', '2026-03-02 20:59:59.999', '2026-03-02 21:00:00.000');
    $add($a, 'tz', 't2', '2026-03-02 21:00:00.000');
    $add($a, 'tz', 't3', '2026-03-01 21:00:00.000');

    // S6 ny: New York springs forward on 2026-03-08 (a 23 hour day)
    $add($a, 'ny', 'ny1', '2026-03-08 04:59:59.000', '2026-03-08 05:00:00.000', '2026-03-09 03:59:59.999', '2026-03-09 04:00:00.000');
    $add($a, 'ny', 'ny2', '2026-03-09 03:59:59.999');

    // S7 idn: identity. A maps anon-r, user-r and third-r to one person, mix-1 and mix-2 to two others, lone is never identified.
    $add($a, 'idn', 'anon-r', '2026-03-02 10:00:00');
    $add($a, 'idn', 'user-r', '2026-03-03 10:00:00');
    $add($a, 'idn', 'third-r', '2026-03-05 10:00:00');
    $add($a, 'idn', 'lone', '2026-03-02 11:00:00', '2026-03-04 11:00:00');
    $add($a, 'idn', 'mix-1', '2026-03-02 12:00:00');
    $add($a, 'idn', 'mix-2', '2026-03-03 12:00:00');
    // project B: the SAME ids and name, a different mapping (anon-r and user-r are two people there)
    $add($b, 'idn', 'anon-r', '2026-03-02 10:00:00');
    $add($b, 'idn', 'user-r', '2026-03-03 10:00:00');
    $add($b, 'idn', 'lone', '2026-03-02 11:00:00', '2026-03-04 11:00:00');

    // S8 flt: filters
    $withProps($a, 'flt', 'f1', '2026-03-02 10:00:00', '{"plan":"pro"}');
    $withProps($a, 'flt', 'f1', '2026-03-03 10:00:00', '{"page":"/pricing"}');
    $withProps($a, 'flt', 'f2', '2026-03-02 10:00:00', '{"plan":"free"}');
    $withProps($a, 'flt', 'f2', '2026-03-03 10:00:00', '{"page":"/pricing"}');
    $withProps($a, 'flt', 'f3', '2026-03-02 11:00:00', '{"plan":"pro"}');
    $withProps($a, 'flt', 'f3', '2026-03-03 11:00:00', '{"plan":"pro"}');
    $withProps($a, 'flt', 'f4', '2026-03-02 12:00:00', '{"plan":"pro","page":"/pricing"}');
    $withProps($a, 'flt', 'f4', '2026-03-04 10:00:00', '{"page":"/Pricing"}');

    foreach (array_chunk($rows, 150) as $chunk) {
        $ch->insertRows($chunk);
    }
    $t = '2026-03-01 00:00:00.000';
    $ch->insertPersonRows([
        [$a, 'anon-r', rpp(1), 0, 0, $t], [$a, 'user-r', rpp(1), 0, 0, $t], [$a, 'third-r', rpp(1), 0, 0, $t],
        [$a, 'mix-1', rpp(2), 0, 0, $t], [$a, 'mix-2', rpp(3), 0, 0, $t],
        [$b, 'anon-r', rpp(101), 0, 0, $t], [$b, 'user-r', rpp(102), 0, 0, $t],
    ]);
    $seeded = true;
}

beforeEach(function () {
    config(['insights.cache_ttl_seconds' => 0]);
    Carbon::setTestNow('2026-06-01 00:00:00 UTC');
    $this->ch = CH::connect();
    seedRetentionProjects($this->ch);
    $this->runner = new InsightRunner($this->ch->reader, new Repository(new ArrayStore));
    // `[size, [retained in period 1..]]` per cohort
    $this->table = fn (int $project, RetentionQuery $query) => retentionTable($this->runner->retention($project, $query));
});

afterEach(fn () => Carbon::setTestNow());

afterAll(function () {
    CH::cleanupRetentionAfterAll();
});

/** @return list<array{0: int, 1: list<?int>}> */
function retentionTable(RetentionResult $result): array
{
    return array_map(fn (array $cohort) => [$cohort['size'], array_slice($cohort['values'], 1)], $result->cohorts);
}

// ------------------------------------------------------------------------------------------------ the definitions

it('counts a simple daily table by first time: a person starts one cohort, the days between do not matter', function () {
    // C0 = p1 p2 p4: p1 returns on days 1 and 3, p2 on day 2, p4 on day 1 (00:00:00.000, one millisecond after its first event).
    // C1 = p3 p6 (p6's day 01 is before the first period: not read). C2 = p5 p8: p5 returns on days 1 and 3 (p8's 03-08 00:00 is not read).
    expect(($this->table)(CH::RET_A, rq('vis1', 'vis1', ['type' => RetentionType::FirstTime])))->toBe([
        [3, [2, 1, 1]],
        [2, [0, 0, 0]],
        [2, [1, 0, 1]],
    ]);
});

it('counts a person in every period they were active in by recurring, and returns need not be consecutive', function () {
    // p1 and p4 are in C0 and C1; p2 in C0 and C2; p5 and p8 in C2; p3 and p6 in C1.
    expect(($this->table)(CH::RET_A, rq('vis1', 'vis1', ['type' => RetentionType::Recurring])))->toBe([
        [3, [2, 1, 1]],
        [4, [0, 1, 0]],
        [3, [1, 0, 1]],
    ]);
});

it('counts a return once however many times it happens inside the period', function () {
    // p5 is active three times on 03-04 and once on 03-05 (00:00:00.000): ONE return in period 1, and none counted in period 0 or twice
    $table = ($this->table)(CH::RET_A, rq('vis1', 'vis1', ['type' => RetentionType::FirstTime]));

    expect($table[2][1][0])->toBe(1);
});

it('puts a period boundary exactly at midnight: 23:59:59.999 is the day before, 00:00:00.000 starts the next', function () {
    // p4: 03-02 23:59:59.999 and 03-03 00:00:00.000 are two periods, so p4 returns in period 1 (see S1). And p5's
    // 03-05 00:00:00.000 is a return in period 1 of C2, and p8's 03-08 00:00:00.000 is exactly the end of the window: not read.
    $first = ($this->table)(CH::RET_A, rq('vis1', 'vis1'));
    $short = ($this->table)(CH::RET_A, rq('vis1', 'vis1', ['periods' => 1]));

    expect($first[0][1][0])->toBe(2)
        ->and($short)->toBe([[3, [2]], [2, [0]], [2, [1]]]);
});

it('keeps a target and a return that are two different events apart', function () {
    // sig2 / buy2. First time: C0 = q1 q2 q4: q2 buys on day 1, q1 on day 2, q4 on day 3; C1 = q3 (its buy is the day BEFORE: no return); C2 is empty.
    expect(($this->table)(CH::RET_A, rq('sig2', 'buy2', ['type' => RetentionType::FirstTime])))->toBe([
        [3, [1, 1, 1]],
        [1, [0, 0, 0]],
        [0, [0, 0, 0]],
    ])
        // Recurring: q4 also starts C2 (its second sig2) and buys on day 1 after it. q5 never signs up: in no cohort.
        ->and(($this->table)(CH::RET_A, rq('sig2', 'buy2', ['type' => RetentionType::Recurring])))->toBe([
            [3, [1, 1, 1]],
            [1, [0, 0, 0]],
            [1, [1, 0, 0]],
        ]);
});

it('numbers weekly periods from Monday: Sunday 23:59:59.999 and Monday 00:00 are different weeks, whole weeks even when the range starts mid week', function () {
    $o = ['period' => RetentionPeriod::Week, 'periods' => 2, 'from' => '2026-03-04', 'to' => '2026-03-19'];   // cohorts: the weeks of 03-02, 03-09, 03-16

    // First time: C0 = w1 (starts Sunday 03-08 23:59:59.999) w2 (Monday 03-02 00:00:00.000, BEFORE the range start of 03-04); both return in weeks 1 and 2.
    // C1 = w4: returns in week 3 (period 2). C2 = w5. w3's first week in the window is W3: no cohort. Sunday 03-01 is the week before: not read.
    expect(($this->table)(CH::RET_A, R::query('wk', 'wk', $o + ['type' => RetentionType::FirstTime])))->toBe([
        [2, [2, 2]],
        [1, [0, 1]],
        [1, [0, 0]],
    ])->and(($this->table)(CH::RET_A, R::query('wk', 'wk', $o + ['type' => RetentionType::Recurring])))->toBe([
        [2, [2, 2]],
        [3, [2, 1]],
        [3, [0, 0]],
    ]);
});

it('numbers monthly periods by calendar month, whole months even when the range starts mid month, and reads up to the end of the follow-up', function () {
    $o = ['period' => RetentionPeriod::Month, 'periods' => 2, 'from' => '2026-01-15', 'to' => '2026-03-15'];   // cohorts: January, February, March

    // m1: Jan 31 23:59:59.999, Feb 1 00:00:00.000, Apr 1; m2: Jan 1 (before the range start, same month), Mar 31 23:59:59.999, May 31 23:59:59.999;
    // m3: Dec 31 (not read), Feb 28, Apr 30; m4: Jun 1 00:00:00.000 is exactly the end of the window: not read.
    expect(($this->table)(CH::RET_A, R::query('mo', 'mo', $o + ['type' => RetentionType::FirstTime])))->toBe([
        [2, [1, 1]],
        [1, [0, 1]],
        [0, [0, 0]],
    ])->and(($this->table)(CH::RET_A, R::query('mo', 'mo', $o + ['type' => RetentionType::Recurring])))->toBe([
        [2, [1, 1]],
        [2, [0, 2]],
        [1, [0, 1]],
    ]);
});

it('cuts the periods at the local midnight of the zone, and at a different place than UTC', function () {
    $o = ['periods' => 1, 'from' => '2026-03-02', 'to' => '2026-03-04'];

    // Istanbul: t1 20:59:59.999 UTC is local 03-02, 21:00:00.000 UTC is local 03-03 (a return); t3 21:00 UTC the day before is local 03-02 00:00 (in C0).
    // UTC: t3's event is before the first period (not read), and t1's two events and t2's are all on 03-02.
    expect(($this->table)(CH::RET_A, R::query('tz', 'tz', $o + ['zone' => 'Europe/Istanbul'])))->toBe([[2, [1]], [1, [0]]])
        ->and(($this->table)(CH::RET_A, R::query('tz', 'tz', $o + ['zone' => 'UTC'])))->toBe([[2, [0]], [0, [0]]]);
});

it('follows daylight saving time: a 23 hour day is one period', function () {
    // America/New_York, 03-08 is 23 hours long. ny1: 23:59:59 EST on 03-07 (C0), 00:00 EST on 03-08 and 23:59:59.999 EDT on 03-08 (both period 1), 00:00 EDT on 03-09 (period 2).
    expect(($this->table)(CH::RET_A, R::query('ny', 'ny', ['zone' => 'America/New_York', 'periods' => 2, 'from' => '2026-03-07', 'to' => '2026-03-09'])))->toBe([
        [1, [1, 1]],
        [1, [0, 0]],
    ]);
});

it('merges the distinct ids of one person, counts never identified ids as themselves, and keeps people apart', function () {
    // A: anon-r, user-r and third-r are ONE person (cohort 03-02; returns on days 1 and 3), lone is a person of its own (returns on day 2),
    // mix-1 and mix-2 are two people: mix-2's first period is day 1 (C1), and it does not make mix-1 return.
    expect(($this->table)(CH::RET_A, rq('idn', 'idn')))->toBe([
        [3, [1, 1, 1]],
        [1, [0, 0, 0]],
        [0, [0, 0, 0]],
    ]);
});

it('keeps two projects with the same ids and event names apart, and an unknown project empty', function () {
    // B maps anon-r and user-r to two people: C0 = anon-r + lone (lone returns on day 2), C1 = user-r.
    expect(($this->table)(CH::RET_B, rq('idn', 'idn')))->toBe([
        [2, [0, 1, 0]],
        [1, [0, 0, 0]],
        [0, [0, 0, 0]],
    ])->and(($this->table)(CH::RET_A, rq('idn', 'idn'))[0])->toBe([3, [1, 1, 1]])
        // C has no rows at all; B has no vis1
        ->and(($this->table)(CH::RET_C, rq('idn', 'idn')))->toBe([[0, [0, 0, 0]], [0, [0, 0, 0]], [0, [0, 0, 0]]])
        ->and(($this->table)(CH::RET_B, rq('vis1', 'vis1')))->toBe([[0, [0, 0, 0]], [0, [0, 0, 0]], [0, [0, 0, 0]]]);
});

it('filters the target and the return event by property', function () {
    $o = ['from' => '2026-03-02', 'to' => '2026-03-04', 'periods' => 2];   // cohorts 03-02 and 03-03
    $plan = fn (FilterOperator $op, ?string $v = null) => ['flt', [new PropertyFilter('plan', $op, $v)]];
    $page = ['flt', [new PropertyFilter('page', FilterOperator::Contains, 'pri')]];

    // target: plan = pro (f1 f3 f4), return: page contains "pri" (f1 on day 1, f4 on day 2, case insensitive); f2's plan is free: not in the cohort
    expect(($this->table)(CH::RET_A, R::query($plan(FilterOperator::Equals, 'pro'), $page, $o)))->toBe([[3, [1, 1]], [0, [0, 0]]])
        // target: plan is set (f1 f2 f3 f4): f2 returns on day 1 as well
        ->and(($this->table)(CH::RET_A, R::query($plan(FilterOperator::IsSet), $page, $o)))->toBe([[4, [2, 1]], [0, [0, 0]]])
        // target: plan != pro. An event WITHOUT a plan is "not pro" too (the property reads as an empty text), so f2's free event (day 0) and
        // f1's page event on day 1 start cohorts; f1's, f3's and f4's day 0 events are pro. f2 returns on day 1 (page /pricing); f4's page event
        // on 03-04 is after the last cohort and starts none.
        ->and(($this->table)(CH::RET_A, R::query($plan(FilterOperator::NotEquals, 'pro'), $page, $o)))->toBe([[1, [1, 0]], [1, [0, 0]]]);
});

it('answers an empty range with zero sizes and no rates, and fills every period that has started with 0', function () {
    $result = $this->runner->retention(CH::RET_A, rq('vis1', 'vis1', ['from' => '2026-04-01', 'to' => '2026-04-04']));

    expect(retentionTable($result))->toBe([[0, [0, 0, 0]], [0, [0, 0, 0]], [0, [0, 0, 0]]])
        ->and(array_column($result->cohorts, 'rates'))->toBe([[null, null, null, null], [null, null, null, null], [null, null, null, null]])
        ->and($result->averages)->toBe(['rates' => [null, null, null, null], 'cohorts' => [0, 0, 0, 0]]);
});

it('makes the periods that have not started null, from the clock, and counts the running one so far', function () {
    // now = 03-04 12:00: the periods of 03-05 and later have not started (their events exist in the database but are masked)
    Carbon::setTestNow('2026-03-04 12:00:00 UTC');

    expect(($this->table)(CH::RET_A, rq('vis1', 'vis1')))->toBe([
        [3, [2, 1, null]],
        [2, [0, null, null]],
        [2, [null, null, null]],
    ]);
    $result = $this->runner->retention(CH::RET_A, rq('vis1', 'vis1'));
    expect($result->cohorts[2]['rates'])->toBe([1.0, null, null, null])
        ->and($result->averages['cohorts'])->toBe([3, 2, 1, 0])
        ->and($result->averages['rates'])->toBe([1.0, 0.4, 0.3333, null]);   // 2/5 (C0 2/3, C1 0/2), 1/3 (C0 only), nobody
});

it('gives rates and a pooled average', function () {
    $result = $this->runner->retention(CH::RET_A, rq('vis1', 'vis1'));

    expect($result->cohorts[0]['rates'])->toBe([1.0, 0.6667, 0.3333, 0.3333])
        ->and($result->cohorts[2]['rates'])->toBe([1.0, 0.5, 0.0, 0.5])
        ->and($result->averages['rates'])->toBe([1.0, 0.4286, 0.1429, 0.2857])   // 3/7, 1/7, 2/7 of the 3 + 2 + 2 people
        ->and($result->averages['cohorts'])->toBe([3, 3, 3, 3]);
});

it('reports the retention failure as query too large, never a wrong number, when the memory cap is hit', function () {
    $tight = new ReadOnlyClickHouseClient(getenv('CLICKHOUSE_INTEGRATION_URL'), 'posthog', 'posthog_ro', getenv('CLICKHOUSE_INTEGRATION_RO_PASSWORD'));
    $runner = new InsightRunner(new ProjectScopedReader(new class($tight) implements ClickHouseReads
    {
        public function __construct(private ReadOnlyClickHouseClient $inner) {}

        public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
        {
            return $this->inner->select($sql, $params, $settings + ['max_memory_usage' => 1000], $timeout);
        }
    }), new Repository(new ArrayStore));

    try {
        $runner->retention(CH::RET_A, rq('vis1', 'vis1'));
        $this->fail('expected the memory cap to fail the retention table');
    } catch (InsightFailed $e) {
        expect($e->kind)->toBe(ClickHouseErrorKind::MemoryLimit)->and($e->getMessage())->toContain('too large');
    }
});

// ------------------------------------------------------------------------------------------------ the grid

it('numbers periods in ClickHouse exactly where the PHP grid starts them, in every zone and over daylight saving time', function (string $zone, RetentionPeriod $period) {
    $ch = CH::connect();
    $q = R::query('a', 'a', ['period' => $period, 'periods' => 30, 'from' => '2023-01-01', 'to' => '2024-01-01', 'zone' => $zone]);
    $function = $period->numberFunction();

    $row = $ch->admin->select(
        "SELECT arrayMap(s -> toInt64({$function}(fromUnixTimestamp64Milli(s * 1000), {tz:String})), arrayMap(x -> toInt64(x), splitByChar(',', {starts:String}))) AS at, "
        ."arrayMap(s -> toInt64({$function}(fromUnixTimestamp64Milli(s * 1000 - 1), {tz:String})), arrayMap(x -> toInt64(x), splitByChar(',', {starts:String}))) AS before",
        ['tz' => $zone, 'starts' => implode(',', $q->starts)],
    )[0];

    $at = $row['at'];
    $before = $row['before'];
    // the counter grows by exactly one from one period start to the next, and the instant before a start is still the previous period
    expect($at)->toBe(range($at[0], $at[0] + count($at) - 1))
        ->and(array_slice($before, 1))->toBe(array_slice($at, 0, -1));
})->with(function () {
    $zones = ['UTC', 'Europe/Istanbul', 'America/New_York', 'America/Santiago', 'America/Havana', 'Australia/Lord_Howe', 'Pacific/Apia', 'Asia/Kolkata', 'Africa/Casablanca', 'Europe/London', 'Asia/Kathmandu'];
    foreach ($zones as $zone) {
        foreach (RetentionPeriod::cases() as $period) {
            yield "{$zone} {$period->value}" => [$zone, $period];
        }
    }
});

// ------------------------------------------------------------------------------------------------ the oracle

/**
 * The truth, computed in PHP from the raw events (no SQL), over the period grid of the query: an event belongs to the period
 * whose start is the last one at or before it. A person is in the cohort of the period of their first target event of the window
 * (`first_time`) or of every cohort period with a target event (`recurring`), and is retained in period k when they have a return
 * event k periods after that.
 *
 * @param  array<string, list<array{0: int, 1: string}>>  $people  person key => [[unix seconds, event], ...]
 * @return list<array{0: int, 1: list<int>}> [size, [retained in period 1..]] per cohort
 */
function retentionOracle(array $people, RetentionQuery $q, int $shift = 0): array
{
    $starts = $q->starts;
    $until = $starts[$q->cohorts + $q->periods];
    $periodOf = function (int $ts) use ($starts): int {
        $index = -1;
        foreach ($starts as $i => $start) {
            if ($start <= $ts) {
                $index = $i;
            } else {
                break;
            }
        }

        return $index;
    };

    $table = array_fill(0, $q->cohorts, [0, array_fill(0, $q->periods, 0)]);
    foreach ($people as $events) {
        $targets = [];
        $returns = [];
        foreach ($events as [$ts, $name]) {
            if ($ts < $starts[0] || $ts >= $until) {
                continue;
            }
            $i = $periodOf($ts);
            if ($name === $q->target->event) {
                $targets[$i] = true;
            }
            if ($name === $q->returning->event) {
                $returns[$i] = true;
            }
        }
        if ($targets === []) {
            continue;
        }
        $cohorts = $q->type === RetentionType::FirstTime ? [min(array_keys($targets))] : array_keys($targets);
        foreach ($cohorts as $c) {
            if ($c >= $q->cohorts) {
                continue;
            }
            $table[$c][0]++;
            for ($k = 1; $k <= $q->periods; $k++) {
                if (isset($returns[$c + $k + $shift])) {
                    $table[$c][1][$k - 1]++;
                }
            }
        }
    }

    return $table;
}

it('agrees with an independent PHP computation on a seeded random dataset, mapped and unmapped ids, boundaries included', function () {
    mt_srand(20260302);
    $project = CH::RET_D;
    $names = ['a', 'b', 'n'];
    $startTs = strtotime('2026-02-23 00:00:00 UTC');
    $span = 11 * 7 * 86400;   // eleven weeks, to 2026-05-11

    // 160 distinct ids; 40% of them belong to one of 25 persons, the rest are never identified
    $mapped = [];
    $personOf = [];
    for ($i = 0; $i < 160; $i++) {
        if (mt_rand(1, 100) <= 40) {
            $personOf["d{$i}"] = rpp(700 + mt_rand(0, 24));
            $mapped[] = [$project, "d{$i}", $personOf["d{$i}"], 0, 0, '2026-02-01 00:00:00.000'];
        }
    }

    $rows = [];
    $people = [];
    $push = function (string $id, string $name, int $ts, int $ms) use (&$rows, &$people, $project, $personOf): void {
        $key = isset($personOf[$id]) ? 'p:'.$personOf[$id] : 'd:'.$id;
        $people[$key][] = [$ts, $name];
        $rows[] = rev($project, $name, $id, gmdate('Y-m-d H:i:s', $ts).sprintf('.%03d', $ms));
    };
    for ($i = 0; $i < 3000; $i++) {
        $id = 'd'.mt_rand(0, 159);
        $ts = $startTs + mt_rand(0, $span);
        $ms = mt_rand(0, 999);
        // a share of the events sits right at a day, week or month boundary of UTC, on both sides of it
        switch (mt_rand(1, 6)) {
            case 1:
                $ts = intdiv($ts, 86400) * 86400;
                $ms = 0;
                break;
            case 2:
                $ts = intdiv($ts, 86400) * 86400 - 1;
                $ms = 999;
                break;
            default:
        }
        $push($id, $names[mt_rand(0, 2)], $ts, $ms);
    }

    $this->ch->deleteRowsOf([$project]);
    foreach (array_chunk($rows, 150) as $chunk) {
        $this->ch->insertRows($chunk);
    }
    $this->ch->insertPersonRows($mapped);

    $zones = ['UTC', 'Europe/Istanbul', 'America/New_York'];
    $checked = 0;
    $mutated = 0;
    $nonTrivial = 0;
    foreach ($zones as $zone) {
        foreach ([RetentionPeriod::Day, RetentionPeriod::Week] as $period) {
            foreach ([RetentionType::FirstTime, RetentionType::Recurring] as $type) {
                foreach ([['a', 'a'], ['a', 'b'], ['n', 'a']] as [$target, $return]) {
                    $isDay = $period === RetentionPeriod::Day;
                    $q = R::query($target, $return, [
                        'period' => $period, 'periods' => $isDay ? 5 : 3, 'type' => $type, 'zone' => $zone,
                        'from' => $isDay ? '2026-03-09' : '2026-03-04', 'to' => $isDay ? '2026-03-16' : '2026-04-02',
                    ]);

                    $expected = retentionOracle($people, $q);
                    $actual = retentionTable($this->runner->retention($project, $q));
                    // the clock is after all the data, so no period is null: compare the numbers as they are
                    expect($actual)->toBe($expected, "{$zone} {$period->value} {$type->value} {$target}>{$return}");

                    $checked++;
                    $nonTrivial += array_sum(array_column($expected, 0)) > 20 && array_sum(array_map(fn ($c) => array_sum($c[1]), $expected)) > 10 ? 1 : 0;
                    // a deliberately wrong oracle (every return one period later) must disagree: the comparison has teeth
                    $mutated += retentionOracle($people, $q, 1) !== $actual ? 1 : 0;
                }
            }
        }
    }

    expect($checked)->toBe(36)
        // not vacuous: the numbers are big and non-trivial, and the shifted oracle is wrong everywhere
        ->and($nonTrivial)->toBe(36)
        ->and($mutated)->toBe(36);
    $this->ch->deleteRowsOf([$project]);
});

it('answers the retention table through the whole API chain: request, queue job, scoped reads, poll, and keeps the tenants apart', function () {
    config(['insights.cache_store' => 'array', 'insights.cache_ttl_seconds' => 60, 'queue.default' => 'sync']);
    Cache::store('array')->flush();
    app()->instance(InsightRunner::class, new InsightRunner($this->ch->reader, Cache::store('array')));
    $a = Project::factory()->create(['id' => CH::RET_A, 'timezone' => 'UTC']);
    $b = Project::factory()->create(['id' => CH::RET_B, 'timezone' => 'UTC']);
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();
    $a->users()->attach($memberA);
    $b->users()->attach($memberB);
    $body = ['target' => ['event' => 'idn'], 'returning' => ['event' => 'idn'], 'periods' => 3, 'date_from' => '2026-03-02', 'date_to' => '2026-03-04'];

    $post = $this->actingAs($memberA)->postJson("/api/projects/{$a->id}/insights/retention", $body)->assertStatus(202);
    $poll = $this->actingAs($memberA)->getJson($post->json('poll_url'))->assertOk();
    $other = $this->actingAs($memberB)->postJson("/api/projects/{$b->id}/insights/retention", $body)->assertStatus(202);
    $otherPoll = $this->actingAs($memberB)->getJson($other->json('poll_url'))->assertOk();

    expect($poll->json('status'))->toBe('done')
        ->and(array_column($poll->json('result.cohorts'), 'size'))->toBe([3, 1, 0])
        ->and($poll->json('result.cohorts.0.values'))->toBe([3, 1, 1, 1])
        ->and($poll->json('result.cohorts.0.rates'))->toBe([1, 0.3333, 0.3333, 0.3333])
        ->and($otherPoll->json('status'))->toBe('done')
        ->and(array_column($otherPoll->json('result.cohorts'), 'size'))->toBe([2, 1, 0])
        ->and($otherPoll->json('result.cohorts.0.values'))->toBe([2, 0, 1, 0]);
    // B's member cannot read A's run
    $this->actingAs($memberB)->getJson("/api/projects/{$b->id}/insights/runs/".$post->json('run_id'))->assertNotFound();
    $this->actingAs($memberB)->getJson($post->json('poll_url'))->assertNotFound();
});
