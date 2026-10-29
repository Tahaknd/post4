<?php

use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Query\EventCondition;
use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\InvalidDateSelection;
use App\Domain\Insights\Query\InvalidInsightQuery;
use App\Domain\Insights\Query\PropertyFilter;
use App\Domain\Insights\Retention\InvalidRetentionQuery;
use App\Domain\Insights\Retention\RetentionPeriod;
use App\Domain\Insights\Retention\RetentionQuery;
use App\Domain\Insights\Retention\RetentionType;
use Tests\Support\RetentionFixtures as R;

function retentionInput(array $o = []): array
{
    return [
        'target' => ['event' => 'signup'],
        'returning' => ['event' => 'visit'],
        'date_preset' => 'last_30_days',
        ...$o,
    ];
}

function retentionFromInput(array $input, string $zone = 'UTC', string $now = '2026-03-14 12:00:00 UTC'): RetentionQuery
{
    return RetentionQuery::fromInput($input, new DateTimeZone($zone), new DateTimeImmutable($now));
}

describe('definition rules', function () {
    it('needs 1 to 30 follow-up periods', function () {
        expect(fn () => R::query(o: ['periods' => 0]))->toThrow(InvalidRetentionQuery::class)
            ->and(fn () => R::query(o: ['periods' => -3]))->toThrow(InvalidRetentionQuery::class)
            ->and(fn () => R::query(o: ['periods' => 31]))->toThrow(InvalidRetentionQuery::class)
            ->and(R::query(o: ['periods' => 1])->periods)->toBe(1)
            ->and(R::query(o: ['periods' => 30])->periods)->toBe(30);
    });

    it('knows the zone or refuses it', function () {
        $range = new DateRange(new DateTimeImmutable('2026-03-02 UTC'), new DateTimeImmutable('2026-03-09 UTC'));
        $make = fn (string $zone) => new RetentionQuery(new EventCondition('a'), new EventCondition('b'), RetentionPeriod::Day, 3, $range, $zone);

        expect($make('Europe/Istanbul')->timezone)->toBe('Europe/Istanbul')
            ->and(fn () => $make('Mars/Base'))->toThrow(InvalidRetentionQuery::class)
            ->and(fn () => $make(''))->toThrow(InvalidRetentionQuery::class)
            ->and(fn () => $make('utc'))->toThrow(InvalidRetentionQuery::class);
    });

    it('validates the event names and the filters of both roles like trends and funnels do', function () {
        $filters = fn (int $n) => array_map(fn (int $i) => new PropertyFilter("k{$i}", FilterOperator::IsSet), range(1, $n));

        expect(fn () => new EventCondition(''))->toThrow(InvalidInsightQuery::class)
            ->and(fn () => new EventCondition(str_repeat('x', 201)))->toThrow(InvalidInsightQuery::class)
            ->and(fn () => new EventCondition("bad\xB1utf8"))->toThrow(InvalidInsightQuery::class)
            ->and((new EventCondition(' a b '))->event)->toBe(' a b ')
            ->and((new EventCondition('a', $filters(5)))->filters)->toHaveCount(5)
            ->and(fn () => new EventCondition('a', $filters(6)))->toThrow(InvalidInsightQuery::class)
            ->and(fn () => new EventCondition('a', ['no']))->toThrow(InvalidInsightQuery::class)
            ->and(fn () => new EventCondition('a', ['x' => new PropertyFilter('k', FilterOperator::IsSet)]))->toThrow(InvalidInsightQuery::class);
    });

    it('allows the same event for both roles, and lists it once in the event names', function () {
        expect(R::query('visit', 'visit')->eventNames())->toBe(['visit'])
            ->and(R::query('signup', 'visit')->eventNames())->toBe(['signup', 'visit']);
    });

    it('requires a range of at most 366 days', function () {
        expect(fn () => R::query(o: ['from' => '2025-01-01', 'to' => '2026-03-01']))->toThrow(InvalidInsightQuery::class)
            ->and(R::query(o: ['from' => '2025-03-02', 'to' => '2026-03-03'])->cohorts)->toBe(366);
    });
});

describe('periods and cohorts', function () {
    it('has one cohort per local day of the range, and reads the follow-up periods after the last', function () {
        $q = R::query(o: ['periods' => 3]);   // 03-02 .. 03-09 (exclusive)

        expect($q->cohorts)->toBe(7)
            ->and($q->readsFrom()->format('Y-m-d H:i'))->toBe('2026-03-02 00:00')
            ->and($q->readsUntil()->format('Y-m-d H:i'))->toBe('2026-03-12 00:00')   // the last cohort is 03-08, plus 3 days, plus its own day
            ->and(count($q->starts))->toBe(7 + 3 + 1);
    });

    it('starts weeks on Monday, and a cohort is the whole week even when the range starts in the middle of it', function () {
        // 2026-03-04 is a Wednesday, 2026-03-25 a Wednesday: the weeks of 03-02, 03-09, 03-16, 03-23
        $q = R::query(o: ['period' => RetentionPeriod::Week, 'from' => '2026-03-04', 'to' => '2026-03-25', 'periods' => 2]);

        expect($q->cohorts)->toBe(4)
            ->and(array_map(fn (int $t) => gmdate('D Y-m-d', $t), array_slice($q->starts, 0, 4)))->toBe(['Mon 2026-03-02', 'Mon 2026-03-09', 'Mon 2026-03-16', 'Mon 2026-03-23'])
            ->and($q->readsUntil()->format('Y-m-d'))->toBe('2026-04-13');
    });

    it('does not make a cohort of the period that starts exactly at the end of the range', function () {
        $q = R::query(o: ['period' => RetentionPeriod::Week, 'from' => '2026-03-02', 'to' => '2026-03-16', 'periods' => 1]);

        expect($q->cohorts)->toBe(2)->and(gmdate('Y-m-d', $q->starts[1]))->toBe('2026-03-09');
    });

    it('uses calendar months of unequal length', function () {
        $q = R::query(o: ['period' => RetentionPeriod::Month, 'from' => '2026-01-15', 'to' => '2026-04-01', 'periods' => 2]);

        expect(array_map(fn (int $t) => gmdate('Y-m-d', $t), $q->starts))->toBe(['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01'])
            ->and($q->cohorts)->toBe(3);
    });

    it('puts the boundaries at the local midnight of the zone, daylight saving time included', function () {
        // Europe/Istanbul is UTC+3 all year; America/New_York springs forward on 2026-03-08 (EST -5 -> EDT -4)
        $istanbul = R::query(o: ['zone' => 'Europe/Istanbul', 'from' => '2026-03-02', 'to' => '2026-03-04', 'periods' => 1]);
        $newYork = R::query(o: ['zone' => 'America/New_York', 'from' => '2026-03-07', 'to' => '2026-03-10', 'periods' => 1]);

        expect(array_map(fn (int $t) => gmdate('m-d H:i', $t), $istanbul->starts))->toBe(['03-01 21:00', '03-02 21:00', '03-03 21:00', '03-04 21:00'])
            ->and(array_map(fn (int $t) => gmdate('m-d H:i', $t), $newYork->starts))->toBe(['03-07 05:00', '03-08 05:00', '03-09 04:00', '03-10 04:00', '03-11 04:00']);
    });

    it('never has more cohorts than the cap of its period for the longest range', function (RetentionPeriod $period, int $max) {
        $q = R::query(o: ['period' => $period, 'from' => '2025-03-02', 'to' => '2026-03-03', 'periods' => 30]);

        expect($q->cohorts)->toBeLessThanOrEqual($max)->and(count($q->starts))->toBe($q->cohorts + 31);
    })->with([[RetentionPeriod::Day, 367], [RetentionPeriod::Week, 54], [RetentionPeriod::Month, 13]]);
});

describe('canonical form', function () {
    it('is stable, versioned and complete', function () {
        $q = R::query('signup', ['visit', [new PropertyFilter('plan', FilterOperator::Equals, 'pro')]], ['period' => RetentionPeriod::Week, 'periods' => 4, 'type' => RetentionType::Recurring, 'zone' => 'Europe/Istanbul']);

        expect($q->canonical())->toBe([
            'v' => 1,
            'target' => ['event' => 'signup', 'filters' => []],
            'returning' => ['event' => 'visit', 'filters' => [['key' => 'plan', 'operator' => 'equals', 'value' => 'pro']]],
            'period' => 'week',
            'periods' => 4,
            'type' => 'recurring',
            'timezone' => 'Europe/Istanbul',
            'range' => ['from' => '2026-03-01T21:00:00.000Z', 'to' => '2026-03-08T21:00:00.000Z'],
        ])->and($q->type())->toBe('retention')
            ->and($q->hash())->toMatch('/\A[0-9a-f]{64}\z/')
            ->and(RetentionQuery::VERSION)->toBe(1);
    });

    it('orders the filters of an event, so the same definition hashes the same whatever order it was written in', function () {
        $x = new PropertyFilter('a', FilterOperator::Equals, '1');
        $y = new PropertyFilter('b', FilterOperator::IsSet);

        expect(R::query(['e', [$x, $y]])->hash())->toBe(R::query(['e', [$y, $x]])->hash());
    });

    it('changes the hash with every part of the definition', function () {
        $hashes = [
            R::query()->hash(),
            R::query('other')->hash(),
            R::query('visit', 'other')->hash(),
            R::query(['visit', [new PropertyFilter('k', FilterOperator::IsSet)]])->hash(),
            R::query('visit', ['visit', [new PropertyFilter('k', FilterOperator::IsSet)]])->hash(),
            R::query(o: ['period' => RetentionPeriod::Week])->hash(),
            R::query(o: ['periods' => 4])->hash(),
            R::query(o: ['type' => RetentionType::Recurring])->hash(),
            R::query(o: ['zone' => 'Europe/Istanbul'])->hash(),
            R::query(o: ['from' => '2026-03-03'])->hash(),
            R::query(o: ['to' => '2026-03-10'])->hash(),
        ];

        expect(array_unique($hashes))->toHaveCount(count($hashes));
    });

    it('rebuilds the very same definition from its canonical array', function () {
        $q = R::query('a', ['b', [new PropertyFilter('plan', FilterOperator::Contains, 'p')]], ['period' => RetentionPeriod::Month, 'periods' => 12, 'type' => RetentionType::Recurring, 'zone' => 'Asia/Kolkata', 'from' => '2026-01-01', 'to' => '2026-03-01']);

        $again = RetentionQuery::fromCanonical($q->canonical());

        expect($again->canonical())->toBe($q->canonical())->and($again->hash())->toBe($q->hash())
            ->and($again->starts)->toBe($q->starts)->and($again->cohorts)->toBe($q->cohorts);
        // and through JSON, the way a queued job and the cache carry it
        expect(RetentionQuery::fromCanonical(json_decode(json_encode($q->canonical()), true))->hash())->toBe($q->hash());
    });

    it('rejects malformed or hostile canonical arrays with InvalidInsightQuery, never a TypeError', function (callable $mutate) {
        $data = R::query()->canonical();

        expect(fn () => RetentionQuery::fromCanonical($mutate($data)))->toThrow(InvalidInsightQuery::class);
    })->with([
        'unknown version' => [fn (array $d) => [...$d, 'v' => 2]],
        'version as text' => [fn (array $d) => [...$d, 'v' => '1']],
        'missing key' => [function (array $d) {
            unset($d['type']);

            return $d;
        }],
        'extra key' => [fn (array $d) => [...$d, 'breakdown' => null]],
        'target a string' => [fn (array $d) => [...$d, 'target' => 'visit']],
        'target without filters' => [fn (array $d) => [...$d, 'target' => ['event' => 'visit']]],
        'target event an int' => [fn (array $d) => [...$d, 'target' => ['event' => 5, 'filters' => []]]],
        'returning null' => [fn (array $d) => [...$d, 'returning' => null]],
        'filters not a list' => [fn (array $d) => [...$d, 'target' => ['event' => 'a', 'filters' => ['x' => []]]]],
        'filter operator unknown' => [fn (array $d) => [...$d, 'target' => ['event' => 'a', 'filters' => [['key' => 'k', 'operator' => 'regex', 'value' => 'x']]]]],
        'period unknown' => [fn (array $d) => [...$d, 'period' => 'hour']],
        'period an int' => [fn (array $d) => [...$d, 'period' => 1]],
        'periods text' => [fn (array $d) => [...$d, 'periods' => '3']],
        'periods float' => [fn (array $d) => [...$d, 'periods' => 3.0]],
        'periods zero' => [fn (array $d) => [...$d, 'periods' => 0]],
        'periods too many' => [fn (array $d) => [...$d, 'periods' => 31]],
        'type unknown' => [fn (array $d) => [...$d, 'type' => 'rolling']],
        'type an array' => [fn (array $d) => [...$d, 'type' => ['recurring']]],
        'timezone unknown' => [fn (array $d) => [...$d, 'timezone' => 'Mars/Base']],
        'timezone an int' => [fn (array $d) => [...$d, 'timezone' => 3]],
        'range wrong format' => [fn (array $d) => [...$d, 'range' => ['from' => '2026-03-01', 'to' => '2026-03-02']]],
        'range null' => [fn (array $d) => [...$d, 'range' => null]],
    ]);
});

describe('from client input', function () {
    it('applies the defaults: day periods, 8 follow-up periods, first time, the project zone', function () {
        $q = retentionFromInput(retentionInput(), 'Europe/Istanbul');

        expect($q->period)->toBe(RetentionPeriod::Day)
            ->and($q->periods)->toBe(8)
            ->and($q->type)->toBe(RetentionType::FirstTime)
            ->and($q->timezone)->toBe('Europe/Istanbul')
            ->and($q->target->filters)->toBe([])
            // last_30_days ending today, 2026-03-14, in Istanbul (UTC+3)
            ->and($q->range->canonical())->toBe(['from' => '2026-02-12T21:00:00.000Z', 'to' => '2026-03-14T21:00:00.000Z'])
            ->and($q->cohorts)->toBe(30);
    });

    it('takes the days and the periods in the zone the client names', function () {
        $q = retentionFromInput(retentionInput(['timezone' => 'America/New_York', 'date_preset' => null, 'date_from' => '2026-03-01', 'date_to' => '2026-03-07', 'period' => 'week']), 'UTC');

        expect($q->range->canonical())->toBe(['from' => '2026-03-01T05:00:00.000Z', 'to' => '2026-03-08T05:00:00.000Z'])
            ->and($q->timezone)->toBe('America/New_York')
            ->and($q->cohorts)->toBe(2);   // Sunday 03-01 belongs to the week of Monday 02-23
    });

    it('resolves a relative range to whole local days, so every request of one day is one definition', function () {
        $morning = retentionFromInput(retentionInput(), 'UTC', '2026-03-14 00:00:01 UTC');
        $evening = retentionFromInput(retentionInput(), 'UTC', '2026-03-14 23:59:59 UTC');
        $nextDay = retentionFromInput(retentionInput(), 'UTC', '2026-03-15 00:00:01 UTC');

        expect($evening->hash())->toBe($morning->hash())->and($nextDay->hash())->not->toBe($morning->hash());
    });

    it('accepts every period, type and a filter on each role', function () {
        $q = retentionFromInput(retentionInput([
            'target' => ['event' => 'a', 'filters' => [['key' => 'plan', 'operator' => 'equals', 'value' => 'pro']]],
            'returning' => ['event' => 'b', 'filters' => [['key' => 'k', 'operator' => 'is_set']]],
            'period' => 'month',
            'periods' => 3,
            'retention_type' => 'recurring',
        ]));

        expect($q->period)->toBe(RetentionPeriod::Month)->and($q->periods)->toBe(3)->and($q->type)->toBe(RetentionType::Recurring)
            ->and($q->target->filters[0]->canonical())->toBe(['key' => 'plan', 'operator' => 'equals', 'value' => 'pro'])
            ->and($q->returning->filters[0]->canonical())->toBe(['key' => 'k', 'operator' => 'is_set', 'value' => null]);
    });

    it('rejects everything else as InvalidInsightQuery, never a TypeError', function (array $input) {
        expect(fn () => retentionFromInput($input))->toThrow(InvalidInsightQuery::class);
    })->with([
        'no target' => [retentionInput(['target' => null])],
        'no returning' => [retentionInput(['returning' => null])],
        'target text' => [retentionInput(['target' => 'a'])],
        'target without event' => [retentionInput(['target' => ['filters' => []]])],
        'event an array' => [retentionInput(['returning' => ['event' => ['a']]])],
        'filters text' => [retentionInput(['target' => ['event' => 'a', 'filters' => 'x']])],
        'filter without operator' => [retentionInput(['target' => ['event' => 'a', 'filters' => [['key' => 'k']]]])],
        'six filters' => [retentionInput(['target' => ['event' => 'a', 'filters' => array_fill(0, 6, ['key' => 'k', 'operator' => 'is_set'])]])],
        'period unknown' => [retentionInput(['period' => 'year'])],
        'period array' => [retentionInput(['period' => ['day']])],
        'periods zero' => [retentionInput(['periods' => 0])],
        'periods too many' => [retentionInput(['periods' => 31])],
        'periods text' => [retentionInput(['periods' => 'eight'])],
        'type unknown' => [retentionInput(['retention_type' => 'rolling'])],
        'unknown zone' => [retentionInput(['timezone' => 'Mars/Base'])],
        'zone an int' => [retentionInput(['timezone' => 5])],
    ]);

    it('rejects an unusable date part as InvalidDateSelection', function (array $input) {
        expect(fn () => retentionFromInput($input))->toThrow(InvalidDateSelection::class);
    })->with([
        'nothing' => [['target' => ['event' => 'a'], 'returning' => ['event' => 'b']]],
        'unknown preset' => [retentionInput(['date_preset' => 'last_year'])],
        'one day only' => [retentionInput(['date_preset' => null, 'date_from' => '2026-03-01'])],
        'impossible day' => [retentionInput(['date_preset' => null, 'date_from' => '2026-02-30', 'date_to' => '2026-03-02'])],
        'end before start' => [retentionInput(['date_preset' => null, 'date_from' => '2026-03-05', 'date_to' => '2026-03-01'])],
        'too long' => [retentionInput(['date_preset' => null, 'date_from' => '2024-01-01', 'date_to' => '2026-01-01'])],
    ]);
});
