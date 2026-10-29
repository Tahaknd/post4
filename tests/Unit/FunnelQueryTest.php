<?php

use App\Domain\Insights\Funnel\ConversionWindow;
use App\Domain\Insights\Funnel\FunnelOrder;
use App\Domain\Insights\Funnel\FunnelQuery;
use App\Domain\Insights\Funnel\FunnelStep;
use App\Domain\Insights\Funnel\InvalidFunnelQuery;
use App\Domain\Insights\Funnel\WindowUnit;
use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\InvalidDateSelection;
use App\Domain\Insights\Query\InvalidInsightQuery;
use App\Domain\Insights\Query\PropertyFilter;
use Tests\Support\FunnelFixtures as F;

function funnelInput(array $o = []): array
{
    return [
        'steps' => [['event' => 'signup'], ['event' => 'purchase']],
        'date_preset' => 'last_7_days',
        ...$o,
    ];
}

function funnelFromInput(array $input, string $zone = 'UTC', string $now = '2026-03-14 12:00:00 UTC'): FunnelQuery
{
    return FunnelQuery::fromInput($input, new DateTimeZone($zone), new DateTimeImmutable($now));
}

describe('definition rules', function () {
    it('needs 2 to 8 steps', function () {
        $step = fn (int $i) => new FunnelStep("e{$i}");
        $range = new DateRange(new DateTimeImmutable('2026-03-01 UTC'), new DateTimeImmutable('2026-03-02 UTC'));

        expect(fn () => new FunnelQuery([], ConversionWindow::default(), $range))->toThrow(InvalidFunnelQuery::class)
            ->and(fn () => new FunnelQuery([$step(1)], ConversionWindow::default(), $range))->toThrow(InvalidFunnelQuery::class)
            ->and(fn () => new FunnelQuery(array_map($step, range(1, 9)), ConversionWindow::default(), $range))->toThrow(InvalidFunnelQuery::class)
            ->and(fn () => new FunnelQuery(['not a step', $step(1)], ConversionWindow::default(), $range))->toThrow(InvalidFunnelQuery::class)
            ->and(fn () => new FunnelQuery(['a' => $step(1), 'b' => $step(2)], ConversionWindow::default(), $range))->toThrow(InvalidFunnelQuery::class)
            ->and((new FunnelQuery([$step(1), $step(2)], ConversionWindow::default(), $range))->steps)->toHaveCount(2)
            ->and((new FunnelQuery(array_map($step, range(1, 8)), ConversionWindow::default(), $range))->steps)->toHaveCount(8);
    });

    it('allows the same event in several steps', function () {
        expect(F::query(['view', 'view', 'buy'])->steps)->toHaveCount(3)
            ->and(F::query(['view', 'view', 'buy'])->eventNames())->toBe(['view', 'buy']);
    });

    it('validates event names like trends does', function (mixed $name) {
        expect(fn () => new FunnelStep($name))->toThrow(InvalidInsightQuery::class);
    })->with([[''], [str_repeat('x', 201)], ["bad\xB1utf8"]]);

    it('accepts event names of 1 to 200 characters, spaces and all', function () {
        expect((new FunnelStep(' a b '))->event)->toBe(' a b ')
            ->and((new FunnelStep(str_repeat('é', 200)))->event)->toHaveLength(200);
    });

    it('limits the filters of a step and of the whole funnel', function () {
        $filters = fn (int $n) => array_map(fn (int $i) => new PropertyFilter("k{$i}", FilterOperator::IsSet), range(1, $n));
        $range = new DateRange(new DateTimeImmutable('2026-03-01 UTC'), new DateTimeImmutable('2026-03-02 UTC'));

        expect((new FunnelStep('a', $filters(5)))->filters)->toHaveCount(5)
            ->and(fn () => new FunnelStep('a', $filters(6)))->toThrow(InvalidFunnelQuery::class)
            ->and(fn () => new FunnelStep('a', ['no']))->toThrow(InvalidFunnelQuery::class)
            // 5 per step, 20 over the funnel: four steps of five pass, a fifth filter-heavy step does not
            ->and((new FunnelQuery([...array_map(fn ($i) => new FunnelStep("e{$i}", $filters(5)), range(1, 4))], ConversionWindow::default(), $range))->steps)->toHaveCount(4)
            ->and(fn () => new FunnelQuery(array_map(fn ($i) => new FunnelStep("e{$i}", $filters($i === 5 ? 1 : 5)), range(1, 5)), ConversionWindow::default(), $range))->toThrow(InvalidFunnelQuery::class);
    });

    it('requires a range of at most 366 days', function () {
        expect(fn () => F::query(['a', 'b'], ['from' => '2025-01-01', 'to' => '2026-03-01']))->toThrow(InvalidInsightQuery::class)
            ->and(F::query(['a', 'b'], ['from' => '2025-03-01', 'to' => '2026-03-02'])->range->from)->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('bounds the conversion window from 1 minute to 90 days', function () {
        $make = fn (int $v, WindowUnit $u) => (new ConversionWindow($v, $u))->seconds;

        expect($make(1, WindowUnit::Minute))->toBe(60)
            ->and($make(90, WindowUnit::Day))->toBe(7776000)
            ->and($make(12, WindowUnit::Week))->toBe(7257600)
            ->and(fn () => $make(0, WindowUnit::Minute))->toThrow(InvalidFunnelQuery::class)
            ->and(fn () => $make(-5, WindowUnit::Day))->toThrow(InvalidFunnelQuery::class)
            ->and(fn () => $make(91, WindowUnit::Day))->toThrow(InvalidFunnelQuery::class)
            ->and(fn () => $make(13, WindowUnit::Week))->toThrow(InvalidFunnelQuery::class)
            ->and(fn () => $make(100001, WindowUnit::Minute))->toThrow(InvalidFunnelQuery::class);
    });

    it('computes where the events are read until: the end of the range plus the window', function () {
        $q = F::query(['a', 'b'], ['from' => '2026-03-10', 'to' => '2026-03-13', 'window' => [36, WindowUnit::Hour]]);

        expect($q->readsUntil()->format('Y-m-d H:i:s'))->toBe('2026-03-14 12:00:00');
    });
});

describe('canonical form', function () {
    it('is stable, versioned and complete', function () {
        $q = F::query(['view', ['buy', [new PropertyFilter('plan', FilterOperator::Equals, 'pro')]]], ['window' => [2, WindowUnit::Hour], 'order' => FunnelOrder::StrictOrder]);

        expect($q->canonical())->toBe([
            'v' => 1,
            'steps' => [
                ['event' => 'view', 'filters' => []],
                ['event' => 'buy', 'filters' => [['key' => 'plan', 'operator' => 'equals', 'value' => 'pro']]],
            ],
            'window' => ['value' => 2, 'unit' => 'hour'],
            'range' => ['from' => '2026-03-10T00:00:00.000Z', 'to' => '2026-03-13T00:00:00.000Z'],
            'order' => 'strict_order',
        ])->and($q->type())->toBe('funnel')
            ->and($q->hash())->toMatch('/\A[0-9a-f]{64}\z/')
            ->and(FunnelQuery::VERSION)->toBe(1);
    });

    it('writes equal durations the same way, so they are one definition', function () {
        $a = F::query(['a', 'b'], ['window' => [14, WindowUnit::Day]]);
        $b = F::query(['a', 'b'], ['window' => [2, WindowUnit::Week]]);
        $c = F::query(['a', 'b'], ['window' => [336, WindowUnit::Hour]]);

        expect($a->canonical()['window'])->toBe(['value' => 2, 'unit' => 'week'])
            ->and($b->hash())->toBe($a->hash())->and($c->hash())->toBe($a->hash())
            ->and(F::query(['a', 'b'], ['window' => [90, WindowUnit::Minute]])->canonical()['window'])->toBe(['value' => 90, 'unit' => 'minute'])
            ->and(F::query(['a', 'b'], ['window' => [24, WindowUnit::Hour]])->canonical()['window'])->toBe(['value' => 1, 'unit' => 'day']);
    });

    it('orders the filters of a step, so the same step hashes the same whatever order it was written in', function () {
        $x = new PropertyFilter('a', FilterOperator::Equals, '1');
        $y = new PropertyFilter('b', FilterOperator::IsSet);

        expect(F::query([['e', [$x, $y]], 'f'])->hash())->toBe(F::query([['e', [$y, $x]], 'f'])->hash());
    });

    it('changes the hash with every part of the definition', function () {
        $base = F::query(['a', 'b']);
        $hashes = [
            $base->hash(),
            F::query(['a', 'c'])->hash(),
            F::query(['b', 'a'])->hash(),
            F::query(['a', 'b', 'c'])->hash(),
            F::query(['a', 'b'], ['window' => [2, WindowUnit::Day]])->hash(),
            F::query(['a', 'b'], ['from' => '2026-03-11'])->hash(),
            F::query(['a', 'b'], ['to' => '2026-03-14'])->hash(),
            F::query(['a', 'b'], ['order' => FunnelOrder::StrictOrder])->hash(),
            F::query([['a', [new PropertyFilter('k', FilterOperator::IsSet)]], 'b'])->hash(),
        ];

        expect(array_unique($hashes))->toHaveCount(count($hashes));
    });

    it('rebuilds the very same definition from its canonical array', function () {
        $q = F::query(['view', ['buy', [new PropertyFilter('plan', FilterOperator::Contains, 'p')]], 'view'], ['window' => [3, WindowUnit::Day], 'order' => FunnelOrder::StrictOrder]);

        $again = FunnelQuery::fromCanonical($q->canonical());

        expect($again->canonical())->toBe($q->canonical())->and($again->hash())->toBe($q->hash());
    });

    it('rejects malformed or hostile canonical arrays with InvalidInsightQuery, never a TypeError', function (callable $mutate) {
        $data = F::query(['a', 'b'])->canonical();

        expect(fn () => FunnelQuery::fromCanonical($mutate($data)))->toThrow(InvalidInsightQuery::class);
    })->with([
        'unknown version' => [fn (array $d) => [...$d, 'v' => 2]],
        'version as text' => [fn (array $d) => [...$d, 'v' => '1']],
        'missing key' => [function (array $d) {
            unset($d['order']);

            return $d;
        }],
        'extra key' => [fn (array $d) => [...$d, 'breakdown' => null]],
        'steps not a list' => [fn (array $d) => [...$d, 'steps' => ['a' => $d['steps'][0], 'b' => $d['steps'][1]]]],
        'steps a string' => [fn (array $d) => [...$d, 'steps' => 'a,b']],
        'one step' => [fn (array $d) => [...$d, 'steps' => [$d['steps'][0]]]],
        'step a string' => [fn (array $d) => [...$d, 'steps' => ['a', 'b']]],
        'step event an int' => [fn (array $d) => [...$d, 'steps' => [['event' => 5, 'filters' => []], $d['steps'][1]]]],
        'step without filters' => [fn (array $d) => [...$d, 'steps' => [['event' => 'a'], $d['steps'][1]]]],
        'step filters not a list' => [fn (array $d) => [...$d, 'steps' => [['event' => 'a', 'filters' => ['x' => []]], $d['steps'][1]]]],
        'filter operator unknown' => [fn (array $d) => [...$d, 'steps' => [['event' => 'a', 'filters' => [['key' => 'k', 'operator' => 'regex', 'value' => 'x']]], $d['steps'][1]]]],
        'window a string' => [fn (array $d) => [...$d, 'window' => '14 days']],
        'window unit unknown' => [fn (array $d) => [...$d, 'window' => ['value' => 1, 'unit' => 'month']]],
        'window value text' => [fn (array $d) => [...$d, 'window' => ['value' => '1', 'unit' => 'day']]],
        'window too long' => [fn (array $d) => [...$d, 'window' => ['value' => 100, 'unit' => 'day']]],
        'window extra key' => [fn (array $d) => [...$d, 'window' => ['value' => 1, 'unit' => 'day', 'x' => 1]]],
        'range wrong format' => [fn (array $d) => [...$d, 'range' => ['from' => '2026-03-01', 'to' => '2026-03-02']]],
        'range null' => [fn (array $d) => [...$d, 'range' => null]],
        'order unknown' => [fn (array $d) => [...$d, 'order' => 'unordered']],
        'order not text' => [fn (array $d) => [...$d, 'order' => 1]],
    ]);
});

describe('from client input', function () {
    it('applies the defaults: 14 days, ordered, the project zone', function () {
        $q = funnelFromInput(funnelInput(), 'Europe/Istanbul');

        expect($q->window->canonical())->toBe(['value' => 2, 'unit' => 'week'])
            ->and($q->order)->toBe(FunnelOrder::Ordered)
            ->and($q->steps[0]->filters)->toBe([])
            // last_7_days ending today, 2026-03-14, in Istanbul (UTC+3): local midnights are 21:00 UTC the evening before
            ->and($q->range->canonical())->toBe(['from' => '2026-03-07T21:00:00.000Z', 'to' => '2026-03-14T21:00:00.000Z']);
    });

    it('takes the days in the zone the client names', function () {
        $q = funnelFromInput(funnelInput(['timezone' => 'America/New_York', 'date_preset' => null, 'date_from' => '2026-03-01', 'date_to' => '2026-03-07']), 'UTC');

        expect($q->range->canonical())->toBe(['from' => '2026-03-01T05:00:00.000Z', 'to' => '2026-03-08T05:00:00.000Z']);
    });

    it('resolves a relative range to whole local days, so every request of one day is one definition', function () {
        $morning = funnelFromInput(funnelInput(), 'UTC', '2026-03-14 00:00:01 UTC');
        $evening = funnelFromInput(funnelInput(), 'UTC', '2026-03-14 23:59:59 UTC');
        $nextDay = funnelFromInput(funnelInput(), 'UTC', '2026-03-15 00:00:01 UTC');

        expect($evening->hash())->toBe($morning->hash())->and($nextDay->hash())->not->toBe($morning->hash());
    });

    it('accepts a window, a strict order and step filters', function () {
        $q = funnelFromInput(funnelInput([
            'steps' => [['event' => 'a', 'filters' => [['key' => 'plan', 'operator' => 'equals', 'value' => 'pro']]], ['event' => 'b']],
            'window' => ['value' => 30, 'unit' => 'minute'],
            'order' => 'strict_order',
        ]));

        expect($q->window->seconds)->toBe(1800)
            ->and($q->order)->toBe(FunnelOrder::StrictOrder)
            ->and($q->steps[0]->filters[0]->canonical())->toBe(['key' => 'plan', 'operator' => 'equals', 'value' => 'pro']);
    });

    it('rejects everything else as InvalidInsightQuery, never a TypeError', function (array $input) {
        expect(fn () => funnelFromInput($input))->toThrow(InvalidInsightQuery::class);
    })->with([
        'no steps' => [funnelInput(['steps' => null])],
        'steps text' => [funnelInput(['steps' => 'a'])],
        'steps map' => [funnelInput(['steps' => ['x' => ['event' => 'a'], 'y' => ['event' => 'b']]])],
        'one step' => [funnelInput(['steps' => [['event' => 'a']]])],
        'step text' => [funnelInput(['steps' => ['a', 'b']])],
        'step without event' => [funnelInput(['steps' => [['filters' => []], ['event' => 'b']]])],
        'event an array' => [funnelInput(['steps' => [['event' => ['a']], ['event' => 'b']]])],
        'filters text' => [funnelInput(['steps' => [['event' => 'a', 'filters' => 'x'], ['event' => 'b']]])],
        'filter without operator' => [funnelInput(['steps' => [['event' => 'a', 'filters' => [['key' => 'k']]], ['event' => 'b']]])],
        'window text' => [funnelInput(['window' => '14d'])],
        'window without unit' => [funnelInput(['window' => ['value' => 3]])],
        'window zero' => [funnelInput(['window' => ['value' => 0, 'unit' => 'day']])],
        'window too long' => [funnelInput(['window' => ['value' => 91, 'unit' => 'day']])],
        'order unknown' => [funnelInput(['order' => 'any'])],
        'order array' => [funnelInput(['order' => ['strict_order']])],
        'unknown zone' => [funnelInput(['timezone' => 'Mars/Base'])],
        'zone an int' => [funnelInput(['timezone' => 5])],
    ]);

    it('rejects an unusable date part as InvalidDateSelection', function (array $input) {
        expect(fn () => funnelFromInput($input))->toThrow(InvalidDateSelection::class);
    })->with([
        'nothing' => [['steps' => [['event' => 'a'], ['event' => 'b']]]],
        'unknown preset' => [funnelInput(['date_preset' => 'last_year'])],
        'one day only' => [funnelInput(['date_preset' => null, 'date_from' => '2026-03-01'])],
        'impossible day' => [funnelInput(['date_preset' => null, 'date_from' => '2026-02-30', 'date_to' => '2026-03-02'])],
        'end before start' => [funnelInput(['date_preset' => null, 'date_from' => '2026-03-05', 'date_to' => '2026-03-01'])],
        'too long' => [funnelInput(['date_preset' => null, 'date_from' => '2024-01-01', 'date_to' => '2026-01-01'])],
    ]);
});
