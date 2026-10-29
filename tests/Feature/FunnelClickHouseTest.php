<?php

use App\Domain\Insights\Funnel\FunnelOrder;
use App\Domain\Insights\Funnel\WindowUnit;
use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\PropertyFilter;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient;
use App\Models\Project;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ClickHouseIntegration as CH;
use Tests\Support\FunnelFixtures as F;

/*
 * Funnels against a real ClickHouse 25.8, through the real chain: read-only user, the scoped views, SQL_project_id,
 * InsightRunner. Opt-in (group "clickhouse"). Fixed instants and hand-computed numbers: every expectation is an exact
 * count per step. The default window is 1 day, the default range the UTC days 2026-03-10, 11 and 12 (`to` is the start of
 * 03-13). Every scenario has event names (or distinct ids) of its own, so scenarios never touch each other's counts, and
 * the funnels of project A run over all of them together.
 *
 * S1 simple 3 steps (view, cart, buy):    u1 view cart buy (10th)                              level 3
 *                                         u2 view cart                                         level 2
 *                                         u3 view                                              level 1
 *                                         u4 view 12th 23:00, cart 23:30, buy 13th 05:00       level 3 (the window runs past the range)
 *                                         u5 cart only, no view                                not in the cohort
 *                                         u6 view on the 9th (before the range), cart, buy     not in the cohort
 *                                         u7 view on the 13th 00:30 (at the end of the range)  not in the cohort
 *                                         => 4 / 3 / 2
 */

pest()->group('clickhouse');

const DAY10 = '2026-03-10';

/** @return array{0: int, 1: string, 2: string, 3: string, 4: string} an insertRows row */
function ev(int $project, string $event, string $distinct, string $ts, string $props = '{}'): array
{
    return [$project, $event, $distinct, $props, $ts];
}

/** Project A's events and mapping: every scenario, once per process. */
function seedFunnelProjects(CH $ch): void
{
    static $seeded = false;
    if ($seeded) {
        return;
    }
    // A clean slate once per process (rows of an aborted run), then everything is seeded in two inserts.
    $ch->deletePersonRowsOf(CH::FUNNEL_IDS);
    $ch->deleteRowsOf(CH::FUNNEL_IDS);
    $a = CH::FUNNEL_A;
    $b = CH::FUNNEL_B;
    $rows = [];
    $add = function (int $project, string $event, string $distinct, string $ts, string $props = '{}') use (&$rows): void {
        $rows[] = ev($project, $event, $distinct, $ts, $props);
    };

    // S1 simple
    foreach ([['view', '2026-03-10 09:00:00'], ['cart', '2026-03-10 09:10:00'], ['buy', '2026-03-10 09:20:00']] as [$e, $t]) {
        $add($a, $e, 'u1', $t);
    }
    $add($a, 'view', 'u2', '2026-03-10 10:00:00');
    $add($a, 'cart', 'u2', '2026-03-10 10:05:00');
    $add($a, 'view', 'u3', '2026-03-11 08:00:00');
    $add($a, 'view', 'u4', '2026-03-12 23:00:00');
    $add($a, 'cart', 'u4', '2026-03-12 23:30:00');
    $add($a, 'buy', 'u4', '2026-03-13 05:00:00');
    $add($a, 'cart', 'u5', '2026-03-10 12:00:00');
    $add($a, 'view', 'u6', '2026-03-09 23:00:00');
    $add($a, 'cart', 'u6', '2026-03-10 00:30:00');
    $add($a, 'buy', 'u6', '2026-03-10 01:00:00');
    $add($a, 'view', 'u7', '2026-03-13 00:30:00');
    $add($a, 'cart', 'u7', '2026-03-13 01:00:00');
    // project B: the SAME ids and names, other timings: only u1 and u2 start inside the range, nobody buys
    $add($b, 'view', 'u1', '2026-03-10 09:00:00');
    $add($b, 'cart', 'u1', '2026-03-10 09:10:00');
    $add($b, 'view', 'u2', '2026-03-10 10:00:00');
    $add($b, 'buy', 'u3', '2026-03-10 11:00:00');

    // S2 out of order (p, q, r)
    foreach ([['q', '10:00'], ['p', '10:10'], ['r', '10:20']] as [$e, $t]) {
        $add($a, $e, 'o1', "2026-03-10 {$t}:00");
    }
    foreach ([['r', '10:00'], ['q', '10:10'], ['p', '10:20']] as [$e, $t]) {
        $add($a, $e, 'o2', "2026-03-10 {$t}:00");
    }
    foreach ([['p', '10:00'], ['r', '10:10'], ['q', '10:20']] as [$e, $t]) {
        $add($a, $e, 'o3', "2026-03-10 {$t}:00");
    }
    foreach ([['p', '10:00'], ['q', '10:10'], ['r', '10:20']] as [$e, $t]) {
        $add($a, $e, 'o4', "2026-03-10 {$t}:00");
    }

    // S3 window (w1, w2), the funnel uses a 1 hour window
    $add($a, 'w1', 'x1', '2026-03-10 10:00:00.000');
    $add($a, 'w2', 'x1', '2026-03-10 11:00:00.000');   // exactly the window: inside
    $add($a, 'w1', 'x2', '2026-03-10 10:00:00.000');
    $add($a, 'w2', 'x2', '2026-03-10 11:00:00.001');   // one millisecond late: outside
    $add($a, 'w1', 'x3', '2026-03-10 10:00:00.000');
    $add($a, 'w2', 'x3', '2026-03-10 10:30:00.000');
    // a later start gets a fresh window (b1, b2): the first b1 expired, the second one converts
    $add($a, 'b1', 'bc', '2026-03-10 08:00:00');
    $add($a, 'b1', 'bc', '2026-03-10 10:00:00');
    $add($a, 'b2', 'bc', '2026-03-10 10:30:00');

    // S4a the same event as consecutive steps (steps: rep, rep, rep_end)
    $add($a, 'rep', 'r1', '2026-03-10 10:00:00');
    $add($a, 'rep', 'r2', '2026-03-10 10:00:00');
    $add($a, 'rep', 'r2', '2026-03-10 10:10:00');
    $add($a, 'rep', 'r3', '2026-03-10 10:00:00');
    $add($a, 'rep', 'r3', '2026-03-10 10:10:00');
    $add($a, 'rep_end', 'r3', '2026-03-10 10:20:00');
    $add($a, 'rep', 'r4', '2026-03-10 10:00:00');
    $add($a, 'rep_end', 'r4', '2026-03-10 10:05:00');
    $add($a, 'rep', 'r4', '2026-03-10 10:10:00');
    $add($a, 'rep', 'r5', '2026-03-10 10:00:00.000');   // two rows of one instant (a duplicate delivery)
    $add($a, 'rep', 'r5', '2026-03-10 10:00:00.000');
    // S4b repeated occurrences of the steps (rp1, rp2): a person counts once per step
    foreach (['10:00', '10:10', '10:20'] as $t) {
        $add($a, 'rp1', 'q1', "2026-03-10 {$t}:00");
    }
    $add($a, 'rp2', 'q1', '2026-03-10 10:30:00');
    $add($a, 'rp1', 'q2', '2026-03-10 10:00:00');
    foreach (['10:10', '10:20', '10:30'] as $t) {
        $add($a, 'rp2', 'q2', "2026-03-10 {$t}:00");
    }

    // S5 identity (m1, m2): see the mapping below
    $add($a, 'm1', 'anon-a', '2026-03-10 10:00:00');
    $add($a, 'm2', 'user-a', '2026-03-10 10:05:00');
    $add($a, 'm1', 'pc-1', '2026-03-10 10:00:00');
    $add($a, 'm1', 'pc-2', '2026-03-10 10:30:00');
    $add($a, 'm2', 'pc-2', '2026-03-10 10:40:00');
    $add($a, 'm1', 'lone-1', '2026-03-10 10:00:00');
    $add($a, 'm2', 'lone-1', '2026-03-10 10:05:00');
    $add($a, 'm1', 'lone-2', '2026-03-10 10:00:00');
    $add($a, 'm1', 'mix-a', '2026-03-10 10:00:00');
    $add($a, 'm2', 'mix-b', '2026-03-10 10:10:00');
    // the same ids in project B belong to other people there
    $add($b, 'm1', 'anon-a', '2026-03-10 10:00:00');
    $add($b, 'm2', 'user-a', '2026-03-10 10:05:00');

    // S6 strict order (t1, t2, t3, and the noise event)
    $add($a, 't1', 'st1', '2026-03-10 10:00:00');
    $add($a, 't2', 'st1', '2026-03-10 10:05:00');
    $add($a, 't3', 'st1', '2026-03-10 10:10:00');
    $add($a, 't1', 'st2', '2026-03-10 10:00:00');
    $add($a, 'noise', 'st2', '2026-03-10 10:03:00');
    $add($a, 't2', 'st2', '2026-03-10 10:05:00');
    $add($a, 't3', 'st2', '2026-03-10 10:10:00');
    $add($a, 't1', 'st3', '2026-03-10 10:00:00');
    $add($a, 't2', 'st3', '2026-03-10 10:05:00');
    $add($a, 'noise', 'st3', '2026-03-10 10:07:00');
    $add($a, 't3', 'st3', '2026-03-10 10:10:00');
    $add($a, 'noise', 'st4', '2026-03-10 09:00:00');
    $add($a, 't1', 'st4', '2026-03-10 10:00:00');
    $add($a, 't2', 'st4', '2026-03-10 10:05:00');
    $add($a, 't1', 'st5', '2026-03-10 10:00:00');
    $add($a, 't1', 'st5', '2026-03-10 10:02:00');
    $add($a, 't2', 'st5', '2026-03-10 10:05:00');
    $add($a, 't3', 'st5', '2026-03-10 10:10:00');
    $add($a, 't1', 'st6', '2026-03-10 10:00:00');
    $add($a, 't3', 'st6', '2026-03-10 10:05:00');

    // S7 property filters (f_view, f_buy)
    $add($a, 'f_view', 'fu1', '2026-03-10 10:00:00', '{"page":"/pricing"}');
    $add($a, 'f_buy', 'fu1', '2026-03-10 10:05:00', '{"plan":"pro"}');
    $add($a, 'f_view', 'fu2', '2026-03-10 10:00:00', '{"page":"/pricing"}');
    $add($a, 'f_buy', 'fu2', '2026-03-10 10:05:00', '{"plan":"free"}');
    $add($a, 'f_view', 'fu3', '2026-03-10 10:00:00', '{"page":"/home"}');
    $add($a, 'f_buy', 'fu3', '2026-03-10 10:05:00', '{"plan":"pro"}');
    $add($a, 'f_view', 'fu4', '2026-03-10 10:00:00', '{"page":"/pricing"}');
    $add($a, 'f_buy', 'fu4', '2026-03-10 10:05:00', '{}');
    // overlapping steps: both steps accept the same page views ("pv" whose page contains "p")
    $add($a, 'pv', 'ov1', '2026-03-10 10:00:00', '{"page":"/p"}');
    $add($a, 'pv', 'ov2', '2026-03-10 10:00:00', '{"page":"/p"}');
    $add($a, 'pv', 'ov2', '2026-03-10 10:10:00', '{"page":"/pp"}');

    $ch->insertRows($rows);

    // The mapping: anon-a and user-a are ONE person, pc-1 and pc-2 are one person; lone-*, mix-* are never identified.
    $t = '2026-03-09 10:00:00.000';
    $ch->insertPersonRows([
        [$a, 'anon-a', fpp(1), 0, 0, $t],
        [$a, 'user-a', fpp(1), 0, 0, $t],
        [$a, 'pc-1', fpp(2), 0, 0, $t],
        [$a, 'pc-2', fpp(2), 0, 0, $t],
        // in B the same two ids are two different people
        [$b, 'anon-a', fpp(101), 0, 0, $t],
        [$b, 'user-a', fpp(102), 0, 0, $t],
    ]);
    $seeded = true;
}

/** Person uuids that are easy to read in a failure. */
function fpp(int $n): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $n);
}

beforeEach(function () {
    config(['insights.cache_ttl_seconds' => 0]);
    $this->ch = CH::connect();
    seedFunnelProjects($this->ch);
    $this->runner = new InsightRunner($this->ch->reader, new Repository(new ArrayStore));
    $this->counts = fn (int $project, array $steps, array $o = []) => F::counts($this->runner->funnel($project, F::query($steps, $o)));
});

afterAll(function () {
    CH::cleanupFunnelAfterAll();
});

it('counts a simple three step funnel per person: the cohort starts in the range, the window runs past its end', function () {
    expect(($this->counts)(CH::FUNNEL_A, ['view', 'cart', 'buy']))->toBe([4, 3, 2]);

    $result = $this->runner->funnel(CH::FUNNEL_A, F::query(['view', 'cart', 'buy']));
    expect($result->entrants)->toBe(4)
        ->and($result->converted)->toBe(2)
        ->and($result->conversionRate)->toBe(0.5)
        ->and(array_column($result->steps, 'dropped_off'))->toBe([0, 1, 1])
        ->and(array_column($result->steps, 'conversion_from_previous'))->toBe([null, 0.75, 0.6667])
        ->and(array_column($result->steps, 'conversion_from_first'))->toBe([1.0, 0.75, 0.5]);
});

it('does not count steps that happen out of order', function () {
    // o1 q p r: p then r, no q after p = 1. o2 r q p = 1. o3 p r q: q after p = 2 (r came before q). o4 p q r = 3.
    expect(($this->counts)(CH::FUNNEL_A, ['p', 'q', 'r']))->toBe([4, 2, 1]);
});

it('closes the conversion window at its end, inclusive, to the millisecond', function () {
    // x1 converts at exactly +1 hour, x2 one millisecond later does not, x3 well inside
    expect(($this->counts)(CH::FUNNEL_A, ['w1', 'w2'], ['window' => [1, WindowUnit::Hour]]))->toBe([3, 2])
        // a window of 61 minutes lets x2 in too; 59 minutes keeps x1 out
        ->and(($this->counts)(CH::FUNNEL_A, ['w1', 'w2'], ['window' => [61, WindowUnit::Minute]]))->toBe([3, 3])
        ->and(($this->counts)(CH::FUNNEL_A, ['w1', 'w2'], ['window' => [59, WindowUnit::Minute]]))->toBe([3, 1]);
});

it('gives a later step 1 event a window of its own when the first one expired', function () {
    // bc: b1 08:00 (expired by 10:30), b1 10:00 -> b2 10:30 within the hour
    expect(($this->counts)(CH::FUNNEL_A, ['b1', 'b2'], ['window' => [1, WindowUnit::Hour]]))->toBe([1, 1])
        ->and(($this->counts)(CH::FUNNEL_A, ['b1', 'b2'], ['window' => [30, WindowUnit::Minute]]))->toBe([1, 1])
        ->and(($this->counts)(CH::FUNNEL_A, ['b1', 'b2'], ['window' => [29, WindowUnit::Minute]]))->toBe([1, 0]);
});

it('counts a person once per step however often they repeat it', function () {
    // q1 does rp1 three times then rp2, q2 does rp1 then rp2 three times: both reach step 2, once
    expect(($this->counts)(CH::FUNNEL_A, ['rp1', 'rp2']))->toBe([2, 2]);
});

it('lets the same event serve consecutive steps, one event per step, equal instants never chaining', function () {
    // steps rep, rep, rep_end. r1: one rep = 1. r2: two reps = 2. r3: two reps then rep_end = 3.
    // r4: rep, rep_end, rep: the second rep has no rep_end after it = 2. r5: two rows at ONE instant = 1.
    expect(($this->counts)(CH::FUNNEL_A, ['rep', 'rep', 'rep_end']))->toBe([5, 3, 1]);
});

it('does not let one event serve two steps when their filters overlap', function () {
    $p = fn () => [new PropertyFilter('page', FilterOperator::Contains, 'p')];
    // pv with a page containing "p" is BOTH steps. ov1 has one such event = level 1; ov2 has two = level 2.
    expect(($this->counts)(CH::FUNNEL_A, [['pv', $p()], ['pv', $p()]]))->toBe([2, 1]);
});

it('filters the events of a step by property', function () {
    $view = [new PropertyFilter('page', FilterOperator::Equals, '/pricing')];
    $plan = fn (FilterOperator $op, ?string $value = null) => [new PropertyFilter('plan', $op, $value)];

    // fu1..fu4 all view; fu3 views /home. Of the /pricing viewers fu1 buys pro, fu2 free, fu4 without a plan.
    expect(($this->counts)(CH::FUNNEL_A, ['f_view', 'f_buy']))->toBe([4, 4])
        ->and(($this->counts)(CH::FUNNEL_A, [['f_view', $view], 'f_buy']))->toBe([3, 3])
        ->and(($this->counts)(CH::FUNNEL_A, [['f_view', $view], ['f_buy', $plan(FilterOperator::Equals, 'pro')]]))->toBe([3, 1])
        ->and(($this->counts)(CH::FUNNEL_A, ['f_view', ['f_buy', $plan(FilterOperator::IsSet)]]))->toBe([4, 3])
        ->and(($this->counts)(CH::FUNNEL_A, ['f_view', ['f_buy', $plan(FilterOperator::NotEquals, 'pro')]]))->toBe([4, 2]);
});

it('merges the distinct ids of one person into one funnel entry, counts never identified ids as themselves', function () {
    // anon-a (m1) and user-a (m2) are one person: level 2. pc-1 and pc-2 are one person: two step 1 events, step 2 on pc-2: level 2.
    // lone-1 (never identified) m1 then m2: level 2. lone-2: level 1. mix-a (m1) and mix-b (m2) are two people: mix-a level 1, mix-b not in.
    expect(($this->counts)(CH::FUNNEL_A, ['m1', 'm2']))->toBe([5, 3]);
});

it('keeps two projects with the same ids and event names apart', function () {
    // B: u1 view+cart, u2 view, u3 buy only: step 1 = u1 u2, cart = u1, buy = 0. A has its own numbers.
    expect(($this->counts)(CH::FUNNEL_B, ['view', 'cart', 'buy']))->toBe([2, 1, 0])
        ->and(($this->counts)(CH::FUNNEL_A, ['view', 'cart', 'buy']))->toBe([4, 3, 2])
        // B maps anon-a and user-a to two different people: m1 by one, m2 by the other = nobody converts
        ->and(($this->counts)(CH::FUNNEL_B, ['m1', 'm2']))->toBe([1, 0])
        // C has no rows at all
        ->and(($this->counts)(CH::FUNNEL_C, ['view', 'cart', 'buy']))->toBe([0, 0, 0]);
});

it('answers an empty range with zero counts and no rates', function () {
    $result = $this->runner->funnel(CH::FUNNEL_A, F::query(['view', 'cart'], ['from' => '2026-04-01', 'to' => '2026-04-08']));

    expect(F::counts($result))->toBe([0, 0])
        ->and($result->entrants)->toBe(0)
        ->and($result->conversionRate)->toBeNull()
        ->and(array_column($result->steps, 'conversion_from_first'))->toBe([null, null]);
});

it('counts a step 1 event on the last second of the range and not on the first instant after it', function () {
    // u7 starts on the 13th 00:30: inside a range that ends on the 14th, outside one that ends on the 13th
    expect(($this->counts)(CH::FUNNEL_A, ['view', 'cart'], ['to' => '2026-03-14']))->toBe([5, 4])
        ->and(($this->counts)(CH::FUNNEL_A, ['view', 'cart'], ['from' => '2026-03-09', 'to' => '2026-03-13']))->toBe([5, 4]);
});

it('shows what strict order changes: any event between the steps stops the chain', function () {
    $steps = ['t1', 't2', 't3'];
    // st1 3, st2 (noise after t1) 3 ordered but 1 strict, st3 (noise after t2) 3 / 2, st4 (noise BEFORE t1) 2 / 2,
    // st5 (t1 twice) 3 / 3, st6 (t3 without t2) 1 / 1
    expect(($this->counts)(CH::FUNNEL_A, $steps, ['order' => FunnelOrder::Ordered]))->toBe([6, 5, 4])
        ->and(($this->counts)(CH::FUNNEL_A, $steps, ['order' => FunnelOrder::StrictOrder]))->toBe([6, 4, 2]);
});

it('reports the funnel failure as query too large, never a wrong number, when the memory cap is hit', function () {
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
        $runner->funnel(CH::FUNNEL_A, F::query(['view', 'cart', 'buy']));
        $this->fail('expected the memory cap to fail the funnel');
    } catch (InsightFailed $e) {
        expect($e->kind)->toBe(ClickHouseErrorKind::MemoryLimit)->and($e->getMessage())->toContain('too large');
    }
});

/**
 * The truth, computed in PHP from the raw events by the plain definition (no SQL, no windowFunnel): a person reaches step
 * k when some step 1 event inside [from, to) is followed by steps 2..k, each strictly later than the one before, the last
 * one at most `window` after the step 1 event. The earliest matching event is always the best choice for the next step,
 * so the greedy chain from every start is exact; the best start wins.
 *
 * @param  array<string, list<array{0: int, 1: string}>>  $people  person key => [[ms, event], ...]
 * @param  list<string>  $steps
 * @return list<int> persons per step
 */
function funnelOracle(array $people, array $steps, int $fromMs, int $toMs, int $windowMs): array
{
    $reached = array_fill(0, count($steps), 0);
    foreach ($people as $events) {
        usort($events, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $best = 0;
        foreach ($events as [$start, $name]) {
            if ($name !== $steps[0] || $start < $fromMs || $start >= $toMs) {
                continue;
            }
            $level = 1;
            $previous = $start;
            for ($k = 1; $k < count($steps); $k++) {
                $next = null;
                foreach ($events as [$ts, $candidate]) {
                    if ($ts > $previous && $ts <= $start + $windowMs && $candidate === $steps[$k]) {
                        $next = $ts;

                        break;
                    }
                }
                if ($next === null) {
                    break;
                }
                $level++;
                $previous = $next;
            }
            $best = max($best, $level);
        }
        for ($k = 0; $k < $best; $k++) {
            $reached[$k]++;
        }
    }

    return $reached;
}

it('agrees with an independent PHP computation on a seeded random dataset, mapped and unmapped ids, ties included', function () {
    mt_srand(20260310);
    $project = CH::FUNNEL_D;
    $names = ['a', 'b', 'c', 'd', 'n'];
    $startMs = strtotime('2026-03-09 12:00:00 UTC') * 1000;
    $span = 4 * 86400 * 1000;   // to 2026-03-13 12:00, past the end of every window below

    // 150 distinct ids; 45% of them belong to one of 20 persons, the rest are never identified
    $mapped = [];
    $personOf = [];
    for ($i = 0; $i < 150; $i++) {
        if (mt_rand(1, 100) <= 45) {
            $personOf["d{$i}"] = fpp(500 + mt_rand(0, 19));
            $mapped[] = [$project, "d{$i}", $personOf["d{$i}"], 0, 0, '2026-03-09 00:00:00.000'];
        }
    }

    $rows = [];
    $people = [];
    $push = function (string $id, string $name, int $ms) use (&$rows, &$people, $project, $personOf): void {
        $key = isset($personOf[$id]) ? 'p:'.$personOf[$id] : 'd:'.$id;
        $people[$key][] = [$ms, $name];
        $rows[] = ev($project, $name, $id, gmdate('Y-m-d H:i:s', intdiv($ms, 1000)).sprintf('.%03d', $ms % 1000));
    };
    for ($i = 0; $i < 1800; $i++) {
        $id = 'd'.mt_rand(0, 149);
        // times on a 30 second grid inside a few hours, so that chains, expiries and equal instants all happen
        $ms = $startMs + mt_rand(0, intdiv($span, 30000)) * 30000 + (mt_rand(1, 10) === 1 ? mt_rand(0, 999) : 0);
        $push($id, $names[mt_rand(0, 4)], $ms);
        if (mt_rand(1, 8) === 1) {
            // a second event of the same person at the very same millisecond (a tie), usually another name
            $push($id, $names[mt_rand(0, 4)], $ms);
        }
    }

    $this->ch->deleteRowsOf([$project]);
    foreach (array_chunk($rows, 150) as $chunk) {
        $this->ch->insertRows($chunk);
    }
    $this->ch->insertPersonRows($mapped);

    $from = strtotime('2026-03-10 00:00:00 UTC') * 1000;
    $to = strtotime('2026-03-12 00:00:00 UTC') * 1000;
    $checked = 0;
    $nonTrivial = 0;
    foreach ([['a', 'b'], ['a', 'b', 'c'], ['a', 'b', 'c', 'd'], ['a', 'a', 'b'], ['a', 'b', 'a'], ['n', 'a', 'n']] as $steps) {
        foreach ([[10, WindowUnit::Minute], [3, WindowUnit::Hour], [1, WindowUnit::Day]] as $window) {
            $windowMs = $window[0] * $window[1]->seconds() * 1000;
            $expected = funnelOracle($people, $steps, $from, $to, $windowMs);
            $actual = ($this->counts)($project, $steps, ['from' => '2026-03-10', 'to' => '2026-03-12', 'window' => $window]);

            expect($actual)->toBe($expected, 'steps '.implode('>', $steps).' window '.$window[0].' '.$window[1]->value);
            $checked++;
            $nonTrivial += $expected[0] > 0 && end($expected) > 0 && end($expected) < $expected[0] ? 1 : 0;
        }
    }

    // not vacuous: eighteen comparisons, and most of them have both converted and dropped people
    expect($checked)->toBe(18)->and($nonTrivial)->toBeGreaterThanOrEqual(8);
    $this->ch->deleteRowsOf([$project]);
});

it('answers the funnel through the whole API chain: request, queue job, scoped reads, poll, and keeps the tenants apart', function () {
    config(['insights.cache_store' => 'array', 'insights.cache_ttl_seconds' => 60, 'queue.default' => 'sync']);
    Cache::store('array')->flush();
    app()->instance(InsightRunner::class, new InsightRunner($this->ch->reader, Cache::store('array')));
    $a = Project::factory()->create(['id' => CH::FUNNEL_A, 'timezone' => 'UTC']);
    $b = Project::factory()->create(['id' => CH::FUNNEL_B, 'timezone' => 'UTC']);
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();
    $a->users()->attach($memberA);
    $b->users()->attach($memberB);
    $body = ['steps' => [['event' => 'view'], ['event' => 'cart'], ['event' => 'buy']], 'window' => ['value' => 1, 'unit' => 'day'], 'date_from' => '2026-03-10', 'date_to' => '2026-03-12'];

    $post = $this->actingAs($memberA)->postJson("/api/projects/{$a->id}/insights/funnel", $body)->assertStatus(202);
    $poll = $this->actingAs($memberA)->getJson($post->json('poll_url'))->assertOk();
    $other = $this->actingAs($memberB)->postJson("/api/projects/{$b->id}/insights/funnel", $body)->assertStatus(202);
    $otherPoll = $this->actingAs($memberB)->getJson($other->json('poll_url'))->assertOk();

    expect($poll->json('status'))->toBe('done')
        ->and(array_column($poll->json('result.steps'), 'count'))->toBe([4, 3, 2])
        ->and($poll->json('result.conversion_rate'))->toBe(0.5)
        ->and($otherPoll->json('status'))->toBe('done')
        ->and(array_column($otherPoll->json('result.steps'), 'count'))->toBe([2, 1, 0]);
    // B's member cannot read A's run
    $this->actingAs($memberB)->getJson("/api/projects/{$b->id}/insights/runs/".$post->json('run_id'))->assertNotFound();
    $this->actingAs($memberB)->getJson($post->json('poll_url'))->assertNotFound();
});
