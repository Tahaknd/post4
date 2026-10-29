<?php

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\TrendsSqlBuilder;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseException;
use App\Infrastructure\ClickHouse\RoSettings;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\Support\ClickHouseIntegration as CH;
use Tests\Support\TrendsFixtures as T;

/*
 * Person based unique users (task 4.4) against a real ClickHouse 25.8, through the real chain: read-only user, the two
 * scoped views, SQL_project_id, InsightRunner. Opt-in (group "clickhouse"). Fixed instants and hand-computed numbers; every
 * expectation is an exact count per bucket. Window: UTC days 2026-03-14 .. 2026-03-17 (four buckets).
 *
 * Project A (PTREND_A), signups, distinct id -> person (P1..P5 are the uuids of pid(1)..pid(5)):
 *   anon1       never identified                        03-14 10:00 (free), 03-15 10:00 (free)
 *   anon-alice  -> P1 (the id BEFORE the identify)      03-14 09:00 (free)
 *   alice       -> P1                                   03-14 12:00 (pro), 03-15 09:00 (pro)
 *   bob, bob2   -> P2                                   03-15 bob 11:00, bob2 12:00 (pro); 03-16 bob2 08:00 (pro)
 *   carol-a     -> P3 at version 0, then MOVED to P2    03-14 13:00, 03-16 09:00 (pro)
 *               by a merge (version 1)
 *   dave        -> P4 at version 0, then a tombstone    03-14 14:00, 03-16 10:00 (pro): has no mapping any more
 * So the persons per day are
 *   03-14  anon1, P1 (anon-alice + alice), P2 (carol-a), dave              = 4  (distinct ids: 5)
 *   03-15  anon1, P1, P2 (bob + bob2)                                      = 3  (distinct ids: 4)
 *   03-16  P2 (bob2 + carol-a), dave                                       = 2  (distinct ids: 3)
 *   03-17  nobody                                                          = 0
 * Project B reuses A's distinct ids with ANOTHER mapping (alice -> PB1, anon-alice -> PB2, bob and the id that looks like
 * a person uuid are unmapped); project C has the same ids as A and no mapping row at all.
 */

pest()->group('clickhouse');

/** Person uuids that are easy to read in a failure. */
function pp(int $n): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $n);
}

/** Seeds the events (admin) of the three projects. Mapping rows are inserted by the tests themselves. */
function seedPersonTrendsEvents(CH $ch): void
{
    $a = CH::PTREND_A;
    $b = CH::PTREND_B;
    $c = CH::PTREND_C;
    $free = '{"plan":"free"}';
    $pro = '{"plan":"pro"}';
    $e = fn (int $project, string $event, string $distinct, string $props, string $ts) => [$project, $event, $distinct, $props, $ts];

    $ch->insertRows([
        $e($a, 'signup', 'anon1', $free, '2026-03-14 10:00:00'),
        $e($a, 'signup', 'anon1', $free, '2026-03-15 10:00:00'),
        $e($a, 'signup', 'anon-alice', $free, '2026-03-14 09:00:00'),
        $e($a, 'signup', 'alice', $pro, '2026-03-14 12:00:00'),
        $e($a, 'signup', 'alice', $pro, '2026-03-15 09:00:00'),
        $e($a, 'signup', 'bob', $pro, '2026-03-15 11:00:00'),
        $e($a, 'signup', 'bob2', $pro, '2026-03-15 12:00:00'),
        $e($a, 'signup', 'bob2', $pro, '2026-03-16 08:00:00'),
        $e($a, 'signup', 'carol-a', $pro, '2026-03-14 13:00:00'),
        $e($a, 'signup', 'carol-a', $pro, '2026-03-16 09:00:00'),
        $e($a, 'signup', 'dave', $pro, '2026-03-14 14:00:00'),
        $e($a, 'signup', 'dave', $pro, '2026-03-16 10:00:00'),
        // another event of the same people: the series are counted separately
        $e($a, 'purchase', 'alice', $pro, '2026-03-14 15:00:00'),
        $e($a, 'purchase', 'anon-alice', $free, '2026-03-14 15:30:00'),
        $e($a, 'purchase', 'bob', $pro, '2026-03-16 15:00:00'),
        // B: the same ids, other people
        $e($b, 'signup', 'alice', $pro, '2026-03-14 10:00:00'),
        $e($b, 'signup', 'anon-alice', $pro, '2026-03-14 11:00:00'),
        $e($b, 'signup', 'bob', $pro, '2026-03-14 12:00:00'),
        $e($b, 'signup', pp(101), $pro, '2026-03-14 13:00:00'),   // an id that LOOKS like person PB1, never identified
        // C: the same ids as A, nothing mapped
        $e($c, 'signup', 'alice', $pro, '2026-03-14 10:00:00'),
        $e($c, 'signup', 'anon-alice', $pro, '2026-03-14 11:00:00'),
        $e($c, 'signup', 'bob', $pro, '2026-03-14 12:00:00'),
    ]);
}

/** Mapping rows, each its own part, A's with a move (out of order) and a tombstone. */
function seedPersonTrendsMapping(CH $ch): void
{
    $a = CH::PTREND_A;
    $b = CH::PTREND_B;
    $t = '2026-03-10 10:00:00.000';

    $ch->insertPersonRows([
        [$a, 'carol-a', pp(2), 1, 0, $t],   // the MOVE arrives first (version 1) ...
        [$a, 'anon-alice', pp(1), 0, 0, $t],
        [$a, 'alice', pp(1), 0, 0, $t],
        [$a, 'bob', pp(2), 0, 0, $t],
        [$a, 'bob2', pp(2), 0, 0, $t],
        [$a, 'carol-a', pp(3), 0, 0, $t],   // ... the older mapping later (out of order)
        [$a, 'dave', pp(4), 0, 0, $t],
        [$a, 'dave', pp(4), 1, 1, $t],      // newest row is a tombstone: dave is a plain distinct id again
        [$b, 'alice', pp(101), 0, 0, $t],
        [$b, 'anon-alice', pp(102), 0, 0, $t],
    ], separateParts: true);
}

beforeEach(function () {
    config(['insights.cache_ttl_seconds' => 0]);
    $this->ch = CH::connect();
    $this->ch->admin->execute('SYSTEM START MERGES posthog.person_distinct_ids');
    $this->ch->deletePersonRowsOf(CH::PTREND_IDS);
    $this->ch->deleteRowsOf(CH::PTREND_IDS);
    seedPersonTrendsEvents($this->ch);
    $this->runner = new InsightRunner($this->ch->reader, new TrendsSqlBuilder, new Repository(new ArrayStore));
    $this->unique = fn (int $project, array $o = []) => T::dataOf($this->runner->trends($project, T::integration(['metric' => Metric::UniqueUsers] + $o)));
});

afterEach(function () {
    $this->ch->admin->execute('SYSTEM START MERGES posthog.person_distinct_ids');
});

afterAll(function () {
    CH::cleanupPersonTrendsAfterAll();
});

it('counts persons per day: ids of one person collapse, a move follows the newest version, a tombstoned id counts as itself', function () {
    $this->ch->admin->execute('SYSTEM STOP MERGES posthog.person_distinct_ids');
    seedPersonTrendsMapping($this->ch);

    // non vacuous: the mapping rows are unmerged, in several parts, carol-a and dave have two physical rows each
    expect($this->ch->personRowCount([CH::PTREND_A]))->toBe(8)
        ->and(((int) $this->ch->admin->select("SELECT count() AS n FROM system.parts WHERE database = 'posthog' AND table = 'person_distinct_ids' AND active")[0]['n']))->toBeGreaterThanOrEqual(5);

    expect(($this->unique)(CH::PTREND_A, ['events' => ['signup', 'purchase']]))->toBe([
        'signup' => [4, 3, 2, 0],
        // purchase: 03-14 alice + anon-alice = one person (P1), 03-16 bob = P2
        'purchase' => [1, 0, 1, 0],
    ]);

    // the same answer after the background merge has collapsed the versions
    $this->ch->admin->execute('SYSTEM START MERGES posthog.person_distinct_ids');
    $this->ch->admin->execute('OPTIMIZE TABLE posthog.person_distinct_ids FINAL');

    expect($this->ch->personRowCount([CH::PTREND_A]))->toBeLessThan(8)
        ->and(($this->unique)(CH::PTREND_A, ['events' => ['signup', 'purchase']]))->toBe(['signup' => [4, 3, 2, 0], 'purchase' => [1, 0, 1, 0]]);
});

it('keeps counting distinct ids when nothing is mapped and never collapses unmapped ids into the nil UUID', function () {
    // project C: three distinct ids on one day and no mapping row at all. If an unmatched row got the nil UUID of the
    // join default and that were counted, the three ids would collapse to one.
    expect(($this->unique)(CH::PTREND_C))->toBe(['signup' => [3, 0, 0, 0]]);
});

it('counts exactly one person per unmapped distinct id even when other ids of the project are mapped', function () {
    $this->ch->insertPersonRows([[CH::PTREND_A, 'alice', pp(1), 0, 0, '2026-03-10 10:00:00.000']]);

    // 03-14: anon1, anon-alice, alice(P1), carol-a, dave = 5 people (only alice is mapped, and alone it is one person)
    // 03-15: anon1, alice(P1), bob, bob2 = 4;   03-16: bob2, carol-a, dave = 3
    expect(($this->unique)(CH::PTREND_A))->toBe(['signup' => [5, 4, 3, 0]]);
});

it('treats an id that looks like a person uuid as a distinct id of its own, not as that person', function () {
    $this->ch->insertPersonRows([
        [CH::PTREND_B, 'alice', pp(101), 0, 0, '2026-03-10 10:00:00.000'],
        [CH::PTREND_B, 'anon-alice', pp(102), 0, 0, '2026-03-10 10:00:00.000'],
    ]);

    // PB1 (alice), PB2 (anon-alice), bob, and the id "...0101" that is NOT identified although it spells PB1's uuid
    expect(($this->unique)(CH::PTREND_B))->toBe(['signup' => [4, 0, 0, 0]]);
});

it('never lets the mapping of one project touch another: same distinct ids, different people', function () {
    seedPersonTrendsMapping($this->ch);

    // A: anon-alice and alice are ONE person. B: the same two ids map to two DIFFERENT people. C: no mapping.
    $a = ($this->unique)(CH::PTREND_A, ['range' => T::window('2026-03-14', '2026-03-15')]);
    $b = ($this->unique)(CH::PTREND_B);
    $c = ($this->unique)(CH::PTREND_C);

    expect($a)->toBe(['signup' => [4]])
        ->and($b)->toBe(['signup' => [4, 0, 0, 0]])   // PB1, PB2, bob, the lookalike
        ->and($c)->toBe(['signup' => [3, 0, 0, 0]])   // alice, anon-alice, bob: nothing leaks in from A or B
        // and A is the same whether or not the other projects have rows
        ->and(($this->unique)(CH::PTREND_A))->toBe(['signup' => [4, 3, 2, 0]]);

    // the reverse direction: removing B's mapping changes B only
    $this->ch->deletePersonRowsOf([CH::PTREND_B]);

    expect(($this->unique)(CH::PTREND_B))->toBe(['signup' => [4, 0, 0, 0]])
        ->and(($this->unique)(CH::PTREND_A))->toBe(['signup' => [4, 3, 2, 0]]);
});

it('reads the mapping at query time: identifying an id changes the counts of events captured BEFORE it', function () {
    // Nothing mapped yet: every distinct id is one user. 03-14: anon1, anon-alice, alice, carol-a, dave.
    expect(($this->unique)(CH::PTREND_A))->toBe(['signup' => [5, 4, 3, 0]]);

    // The identify of anon-alice and alice (events of 03-14 and 03-15 already exist) collapses them retroactively.
    $this->ch->insertPersonRows([
        [CH::PTREND_A, 'anon-alice', pp(1), 0, 0, '2026-03-14 12:00:00.000'],
        [CH::PTREND_A, 'alice', pp(1), 0, 0, '2026-03-14 12:00:00.000'],
    ]);

    expect(($this->unique)(CH::PTREND_A))->toBe(['signup' => [4, 4, 3, 0]]);

    // bob and bob2 become one person, and a merge moves carol-a and dave under it: the days they were seen on change too.
    $this->ch->insertPersonRows([
        [CH::PTREND_A, 'bob', pp(2), 0, 0, '2026-03-15 12:00:00.000'],
        [CH::PTREND_A, 'bob2', pp(2), 0, 0, '2026-03-15 12:00:00.000'],
        [CH::PTREND_A, 'carol-a', pp(2), 1, 0, '2026-03-15 12:00:00.000'],
        [CH::PTREND_A, 'dave', pp(2), 1, 0, '2026-03-15 12:00:00.000'],
    ]);

    // 03-14: anon1, P1, P2 (carol-a, dave) = 3;  03-15: anon1, P1, P2 (bob, bob2) = 3;  03-16: P2 (bob2, carol-a, dave) = 1
    expect(($this->unique)(CH::PTREND_A))->toBe(['signup' => [3, 3, 1, 0]]);
});

it('does not change total events: the same numbers with and without any mapping, and the mapping is not even read', function () {
    $total = fn () => T::dataOf($this->runner->trends(CH::PTREND_A, T::integration(['events' => ['signup', 'purchase']])));
    $before = $total();

    seedPersonTrendsMapping($this->ch);

    expect($before)->toBe(['signup' => [5, 4, 3, 0], 'purchase' => [2, 0, 1, 0]])
        ->and($total())->toBe($before);

    // nor does it read the mapping view: the query log entry of the total metric lists only the events view as a table
    $tag = 'ptrend-total-'.bin2hex(random_bytes(4));
    $q = T::integration(['events' => ['signup']]);
    $built = (new TrendsSqlBuilder)->build($q);
    $this->ch->reader->select(CH::PTREND_A, $built->sql, $built->params, [RoSettings::LOG_COMMENT => $tag]);
    $this->ch->admin->execute('SYSTEM FLUSH LOGS');
    $views = $this->ch->admin->select("SELECT has(tables, 'posthog.project_person_distinct_ids_v') AS person, has(tables, 'posthog.project_events_v') AS events FROM system.query_log WHERE log_comment = {c:String} AND type = 'QueryFinish'", ['c' => $tag]);

    expect($views)->toHaveCount(1)->and((int) $views[0]['events'])->toBe(1)->and((int) $views[0]['person'])->toBe(0);
});

it('counts persons per week and per hour', function () {
    seedPersonTrendsMapping($this->ch);

    // weeks start on Monday: the week of 03-09 holds 03-14 and 03-15, the week of 03-16 holds 03-16
    //   week 1: anon1, P1, P2 (bob, bob2, carol-a), dave = 4;  week 2: P2, dave = 2
    $week = ($this->unique)(CH::PTREND_A, ['range' => T::window('2026-03-09', '2026-03-23'), 'interval' => Interval::Week]);
    // hours of 03-14 09:00..10:00: anon-alice (P1) alone, then anon1 alone
    $hour = ($this->unique)(CH::PTREND_A, ['range' => T::window('2026-03-14 09:00', '2026-03-14 12:00'), 'interval' => Interval::Hour]);

    expect($week)->toBe(['signup' => [4, 2]])
        ->and($hour)->toBe(['signup' => [1, 1, 0]]);   // 09: anon-alice, 10: anon1, 11: none
});

it('breaks down persons by a property: a person counts in every group it has events in, and Other is a union of persons', function () {
    seedPersonTrendsMapping($this->ch);

    // plan: anon1 and anon-alice are free, everything else pro
    //   free: 03-14 anon1 + P1 (anon-alice) = 2;  03-15 anon1 = 1
    //   pro:  03-14 P1 (alice), P2 (carol-a), dave = 3;  03-15 P1, P2 (bob, bob2) = 2;  03-16 P2 (bob2, carol-a), dave = 2
    $all = ($this->unique)(CH::PTREND_A, ['breakdown' => Breakdown::byProperty('plan', 5)]);
    $top1 = ($this->unique)(CH::PTREND_A, ['breakdown' => Breakdown::byProperty('plan', 1)]);

    // P1 is in BOTH groups on 03-14 (anon-alice free, alice pro), so the groups sum to more than the 4 people of the day
    expect($all)->toBe(['signup|pro' => [3, 2, 2, 0], 'signup|free' => [2, 1, 0, 0]])   // series are ordered by total, largest first
        // the top one by unique persons over the window is pro (3 people vs free 2); Other is free
        ->and($top1)->toBe(['signup|pro' => [3, 2, 2, 0], 'signup|'.Breakdown::OTHER_LABEL => [2, 1, 0, 0]]);
});

it('fails with the memory limit kind, not with a wrong number, when the mapping join does not fit', function () {
    seedPersonTrendsMapping($this->ch);
    $built = (new TrendsSqlBuilder)->build(T::integration(['metric' => Metric::UniqueUsers]));

    try {
        $this->ch->reader->select(CH::PTREND_A, $built->sql, $built->params, [RoSettings::MAX_MEMORY_USAGE => 1000]);
        $this->fail('the query was expected to exceed a 1000 byte memory limit');
    } catch (ClickHouseException $e) {
        expect($e->kind)->toBe(ClickHouseErrorKind::MemoryLimit)
            ->and($e->kind->userMessage())->toContain('too large');
    }
});
