<?php

use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\BreakdownColumn;
use App\Domain\Insights\Trends\BucketAxis;
use App\Domain\Insights\Trends\DateRange;
use App\Domain\Insights\Trends\FilterOperator;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\InvalidTrendsQuery;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\PropertyFilter;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Infrastructure\ClickHouse\ProjectScopedReader;

function utcWindow(string $from = '2026-03-01', string $to = '2026-03-08'): DateRange
{
    return new DateRange(new DateTimeImmutable($from.' UTC'), new DateTimeImmutable($to.' UTC'));
}

function tq(array $override = []): TrendsQuery
{
    $a = $override + [
        'events' => ['$pageview'],
        'metric' => Metric::TotalEvents,
        'range' => utcWindow(),
        'interval' => Interval::Day,
        'timezone' => 'UTC',
        'breakdown' => null,
        'filters' => [],
    ];

    return new TrendsQuery($a['events'], $a['metric'], $a['range'], $a['interval'], $a['timezone'], $a['breakdown'], $a['filters']);
}

describe('definition rules', function () {
    it('accepts a minimal definition', function () {
        $q = tq();

        expect($q->events)->toBe(['$pageview'])->and($q->timezone())->toBe('UTC')->and($q->filters)->toBe([]);
    });

    it('requires between 1 and 10 distinct event names', function (array $events) {
        expect(fn () => tq(['events' => $events]))->toThrow(InvalidTrendsQuery::class);
    })->with([
        'none' => [[]],
        'eleven' => [array_map(fn ($i) => "e{$i}", range(1, 11))],
        'duplicate' => [['a', 'b', 'a']],
        'empty name' => [['']],
        'too long' => [[str_repeat('a', 201)]],
        'invalid utf8' => [["bad\xB1"]],
        'not a list' => [['x' => 'a']],
    ]);

    it('accepts ten events and an event name of 200 characters', function () {
        expect(tq(['events' => array_map(fn ($i) => "e{$i}", range(1, 10))])->events)->toHaveCount(10)
            ->and(tq(['events' => [str_repeat('é', 200)]])->events)->toHaveCount(1);
    });

    it('requires an end after the start and at most 366 days', function () {
        expect(fn () => new DateRange(new DateTimeImmutable('2026-03-02'), new DateTimeImmutable('2026-03-02')))->toThrow(InvalidTrendsQuery::class)
            ->and(fn () => new DateRange(new DateTimeImmutable('2026-03-02'), new DateTimeImmutable('2026-03-01')))->toThrow(InvalidTrendsQuery::class)
            ->and(fn () => new DateRange(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2026-01-03')))->toThrow(InvalidTrendsQuery::class, '366');
        // exactly 366 days is fine
        expect((new DateRange(new DateTimeImmutable('2025-01-01 UTC'), new DateTimeImmutable('2026-01-02 UTC')))->to->format('Y-m-d'))->toBe('2026-01-02');
    });

    it('normalises the range to UTC', function () {
        $r = new DateRange(new DateTimeImmutable('2026-03-01 03:00 +03:00'), new DateTimeImmutable('2026-03-02 03:00 +03:00'));

        expect($r->from->format('c'))->toBe('2026-03-01T00:00:00+00:00')->and($r->to->format('c'))->toBe('2026-03-02T00:00:00+00:00');
    });

    it('builds whole local days with an exclusive end', function () {
        $r = DateRange::localDays('2026-03-14', '2026-03-15', new DateTimeZone('Europe/Istanbul'));

        expect($r->from->format('c'))->toBe('2026-03-13T21:00:00+00:00')->and($r->to->format('c'))->toBe('2026-03-15T21:00:00+00:00');
        expect(fn () => DateRange::localDays('2026-02-30', '2026-03-01', new DateTimeZone('UTC')))->toThrow(InvalidTrendsQuery::class)
            ->and(fn () => DateRange::localDays('nope', '2026-03-01', new DateTimeZone('UTC')))->toThrow(InvalidTrendsQuery::class);
    });

    it('accepts only PHP time zone identifiers', function (string $tz, bool $ok) {
        if ($ok) {
            expect(tq(['timezone' => $tz])->timezone())->toBe($tz);
        } else {
            expect(fn () => tq(['timezone' => $tz]))->toThrow(InvalidTrendsQuery::class, 'time zone');
        }
    })->with([
        ['UTC', true], ['Europe/Istanbul', true], ['Asia/Kolkata', true], ['America/St_Johns', true],
        ['utc', false], ['Europe/Atlantis', false], ['+03:00', false], ["UTC'; DROP TABLE x", false], ['', false], ['EST5EDT; ', false],
    ]);

    it('limits property filters to ten and validates each', function () {
        $ok = array_map(fn ($i) => new PropertyFilter("k{$i}", FilterOperator::IsSet), range(1, 10));

        expect(tq(['filters' => $ok])->filters)->toHaveCount(10)
            ->and(fn () => tq(['filters' => [...$ok, new PropertyFilter('k11', FilterOperator::IsSet)]]))->toThrow(InvalidTrendsQuery::class)
            ->and(fn () => tq(['filters' => ['not a filter']]))->toThrow(InvalidTrendsQuery::class);
    });

    it('validates filters', function (callable $make) {
        expect($make)->toThrow(InvalidTrendsQuery::class);
    })->with([
        'empty key' => [fn () => new PropertyFilter('', FilterOperator::Equals, 'x')],
        'long key' => [fn () => new PropertyFilter(str_repeat('k', 201), FilterOperator::Equals, 'x')],
        'equals without value' => [fn () => new PropertyFilter('k', FilterOperator::Equals)],
        'contains empty' => [fn () => new PropertyFilter('k', FilterOperator::Contains, '')],
        'is set with value' => [fn () => new PropertyFilter('k', FilterOperator::IsSet, 'x')],
        'long value' => [fn () => new PropertyFilter('k', FilterOperator::Equals, str_repeat('v', 1001))],
        'bad utf8 value' => [fn () => new PropertyFilter('k', FilterOperator::Equals, "\xB1")],
    ]);

    it('allows an empty value for equals and not equals', function () {
        expect((new PropertyFilter('k', FilterOperator::Equals, ''))->value)->toBe('')
            ->and((new PropertyFilter('k', FilterOperator::NotEquals, ''))->value)->toBe('');
    });

    it('validates the breakdown', function (callable $make) {
        expect($make)->toThrow(InvalidTrendsQuery::class);
    })->with([
        'top 0' => [fn () => Breakdown::byColumn(BreakdownColumn::Browser, 0)],
        'top 26' => [fn () => Breakdown::byProperty('plan', 26)],
        'empty key' => [fn () => Breakdown::byProperty('')],
        'long key' => [fn () => Breakdown::byProperty(str_repeat('k', 201))],
    ]);

    it('only offers breakdown columns that exist in the scoped relation', function () {
        foreach (BreakdownColumn::cases() as $column) {
            expect(ProjectScopedReader::COLUMNS)->toContain($column->value)
                ->and($column->valueSql())->toBe('toString('.$column->value.')');
        }
        expect(array_map(fn ($c) => $c->value, BreakdownColumn::cases()))->not->toContain('uuid', 'properties', 'timestamp', 'project_id', 'created_at');
    });

    it('refuses a query whose result could pass the row cap', function () {
        // hourly over 366 days: 8785 buckets x 1 series is ok, x 2 is not
        $year = utcWindow('2026-01-01', '2026-12-31');

        expect(count(tq(['range' => $year, 'interval' => Interval::Hour])->buckets()))->toBeGreaterThan(8000);
        expect(fn () => tq(['range' => $year, 'interval' => Interval::Hour, 'events' => ['a', 'b']]))->toThrow(InvalidTrendsQuery::class, 'rows');
        expect(fn () => tq(['range' => utcWindow('2026-01-01', '2026-03-01'), 'interval' => Interval::Hour, 'breakdown' => Breakdown::byColumn(BreakdownColumn::Browser)]))->toThrow(InvalidTrendsQuery::class);
        // the same breakdown at day granularity is fine: 31 x 1 x 11
        expect(tq(['range' => utcWindow('2026-01-01', '2026-02-01'), 'breakdown' => Breakdown::byColumn(BreakdownColumn::Browser)])->buckets())->toHaveCount(31);
    });
});

describe('canonical form and hash', function () {
    it('is stable for equal definitions and ignores filter order', function () {
        $f1 = new PropertyFilter('a', FilterOperator::Equals, '1');
        $f2 = new PropertyFilter('b', FilterOperator::Contains, 'x');

        expect(tq(['filters' => [$f1, $f2]])->hash())->toBe(tq(['filters' => [$f2, $f1]])->hash())
            ->and(tq()->hash())->toBe(tq()->hash())
            ->and(tq()->hash())->toMatch('/^[0-9a-f]{64}$/');
    });

    it('changes with every part of the definition', function () {
        $base = tq()->hash();
        $variants = [
            tq(['events' => ['$pageview', 'x']]),
            tq(['events' => ['other']]),
            tq(['metric' => Metric::UniqueUsers]),
            tq(['range' => utcWindow('2026-03-01', '2026-03-09')]),
            tq(['range' => utcWindow('2026-03-02', '2026-03-08')]),
            tq(['interval' => Interval::Week]),
            tq(['timezone' => 'Europe/Istanbul']),
            tq(['breakdown' => Breakdown::byColumn(BreakdownColumn::Browser)]),
            tq(['breakdown' => Breakdown::byColumn(BreakdownColumn::Browser, 5)]),
            tq(['breakdown' => Breakdown::byProperty('browser')]),
            tq(['filters' => [new PropertyFilter('a', FilterOperator::Equals, '1')]]),
            tq(['filters' => [new PropertyFilter('a', FilterOperator::Equals, '2')]]),
            tq(['filters' => [new PropertyFilter('a', FilterOperator::NotEquals, '1')]]),
        ];
        $hashes = array_map(fn (TrendsQuery $q) => $q->hash(), $variants);

        expect($hashes)->not->toContain($base)->and(array_unique($hashes))->toHaveCount(count($hashes));
    });

    it('keeps the event order, which is the series order', function () {
        expect(tq(['events' => ['a', 'b']])->hash())->not->toBe(tq(['events' => ['b', 'a']])->hash());
    });

    it('carries a version in the canonical form', function () {
        expect(tq()->canonical())->toMatchArray(['v' => TrendsQuery::VERSION, 'timezone' => 'UTC', 'interval' => 'day', 'metric' => 'total_events']);
    });
});

describe('bucket axis', function () {
    it('lists local midnights for days, with the right UTC instants', function () {
        $r = DateRange::localDays('2026-03-14', '2026-03-16', new DateTimeZone('Europe/Istanbul'));

        $starts = BucketAxis::starts($r, Interval::Day, new DateTimeZone('Europe/Istanbul'));

        expect(array_map(fn ($t) => gmdate('c', $t), $starts))->toBe(['2026-03-13T21:00:00+00:00', '2026-03-14T21:00:00+00:00', '2026-03-15T21:00:00+00:00']);
    });

    it('floors the first bucket before the start of the range', function () {
        $r = new DateRange(new DateTimeImmutable('2026-03-11 12:34:56 UTC'), new DateTimeImmutable('2026-03-12 02:00:00 UTC'));

        expect(array_map(fn ($t) => gmdate('c', $t), BucketAxis::starts($r, Interval::Day, new DateTimeZone('UTC'))))->toBe(['2026-03-11T00:00:00+00:00', '2026-03-12T00:00:00+00:00'])
            ->and(array_map(fn ($t) => gmdate('H:i', $t), BucketAxis::starts($r, Interval::Hour, new DateTimeZone('UTC'))))->toHaveCount(14)->and(BucketAxis::starts($r, Interval::Hour, new DateTimeZone('UTC'))[0])->toBe(strtotime('2026-03-11 12:00:00 UTC'));
    });

    it('aligns hours to the LOCAL hour in half hour zones', function () {
        $r = new DateRange(new DateTimeImmutable('2026-03-11 12:34:56 UTC'), new DateTimeImmutable('2026-03-11 14:00:00 UTC'));

        $starts = BucketAxis::starts($r, Interval::Hour, new DateTimeZone('Asia/Kolkata'));

        // 12:34 UTC is 18:04 IST, so the first bucket is 18:00 IST = 12:30 UTC
        expect(array_map(fn ($t) => gmdate('H:i', $t), $starts))->toBe(['12:30', '13:30']);
    });

    it('starts weeks on Monday and months on the 1st, in the project zone', function () {
        $zone = new DateTimeZone('Europe/Istanbul');
        // Sunday 2026-03-15 and Tuesday 2026-03-31 20:00 UTC (= Wednesday 2026-04-01 in Istanbul)
        $weeks = BucketAxis::starts(new DateRange(new DateTimeImmutable('2026-03-15 12:00 UTC'), new DateTimeImmutable('2026-03-24 12:00 UTC')), Interval::Week, $zone);
        $months = BucketAxis::starts(new DateRange(new DateTimeImmutable('2026-03-31 22:30 UTC'), new DateTimeImmutable('2026-05-02 00:00 UTC')), Interval::Month, $zone);

        expect(array_map(fn ($t) => (new DateTimeImmutable('@'.$t))->setTimezone($zone)->format('D Y-m-d H:i'), $weeks))->toBe(['Mon 2026-03-09 00:00', 'Mon 2026-03-16 00:00', 'Mon 2026-03-23 00:00'])
            ->and(array_map(fn ($t) => (new DateTimeImmutable('@'.$t))->setTimezone($zone)->format('Y-m-d H:i'), $months))->toBe(['2026-04-01 00:00', '2026-05-01 00:00']);
    });

    it('keeps one bucket per local day across a DST change', function () {
        $zone = new DateTimeZone('Europe/Berlin');
        $r = DateRange::localDays('2026-03-28', '2026-03-31', $zone);

        $starts = BucketAxis::starts($r, Interval::Day, $zone);

        // Berlin springs forward on 2026-03-29: that local day has 23 hours, the others 24
        expect($starts)->toHaveCount(4)->and($starts[1] - $starts[0])->toBe(86400)->and($starts[2] - $starts[1])->toBe(82800)->and($starts[3] - $starts[2])->toBe(86400);
    });

    it('gives an hour bucket for each real hour across the autumn change (25 hours)', function () {
        $zone = new DateTimeZone('Europe/Berlin');
        $r = DateRange::localDays('2026-10-25', '2026-10-25', $zone);

        expect(BucketAxis::starts($r, Interval::Hour, $zone))->toHaveCount(25);
    });
});
