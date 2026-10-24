<?php

use App\Domain\Insights\Sql\Params;
use App\Domain\Insights\Sql\ParamType;
use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\BreakdownColumn;
use App\Domain\Insights\Trends\DateRange;
use App\Domain\Insights\Trends\FilterOperator;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\PropertyFilter;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsSqlBuilder;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;

/** A recording stand-in for the read-only client, so the REAL reader lint runs over every generated query. */
function recordingReader(): array
{
    $fake = new class implements ClickHouseReads
    {
        public array $calls = [];

        public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
        {
            $this->calls[] = compact('sql', 'params', 'settings', 'timeout');

            return [];
        }
    };

    return [$fake, new ProjectScopedReader($fake)];
}

function sample(array $o = []): TrendsQuery
{
    $a = $o + [
        'events' => ['a', 'b'],
        'metric' => Metric::TotalEvents,
        'interval' => Interval::Day,
        'timezone' => 'Europe/Istanbul',
        'breakdown' => null,
        'filters' => [],
        'from' => '2026-03-01',
        'to' => '2026-03-08',
    ];

    return new TrendsQuery(
        $a['events'], $a['metric'],
        new DateRange(new DateTimeImmutable($a['from'].' UTC'), new DateTimeImmutable($a['to'].' UTC')),
        $a['interval'], $a['timezone'], $a['breakdown'], $a['filters'],
    );
}

/** @return array<string, mixed> every parameter with dates as strings */
function plain(array $params): array
{
    return array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s.v') : $v, $params);
}

describe('SQL snapshots', function () {
    it('builds the plain total-events query', function () {
        $built = (new TrendsSqlBuilder)->build(sample());

        expect($built->sql)->toBe(
            'SELECT event AS series, toUnixTimestamp(toDateTime(toStartOfInterval(timestamp, INTERVAL 1 DAY, {tz:String}), {tz:String})) AS bucket, uniqExact(uuid) AS value '
            .'FROM project_events WHERE event IN {names:Array(String)} AND timestamp >= {from:DateTime64(3)} AND timestamp < {to:DateTime64(3)} '
            .'GROUP BY series, bucket ORDER BY bucket ASC, series ASC LIMIT 10001'
        )->and(plain($built->params))->toBe([
            'names' => ['a', 'b'],
            'from' => '2026-03-01 00:00:00.000',
            'to' => '2026-03-08 00:00:00.000',
            'tz' => 'Europe/Istanbul',
        ]);
    });

    it('uses uniqExact(distinct_id) for unique users and one fixed fragment per interval', function (Interval $interval, string $fragment) {
        $sql = (new TrendsSqlBuilder)->build(sample(['metric' => Metric::UniqueUsers, 'interval' => $interval]))->sql;

        expect($sql)->toContain('uniqExact(distinct_id) AS value')->and($sql)->toContain("toStartOfInterval(timestamp, {$fragment}, {tz:String})");
    })->with([
        [Interval::Hour, 'INTERVAL 1 HOUR'], [Interval::Day, 'INTERVAL 1 DAY'], [Interval::Week, 'INTERVAL 1 WEEK'], [Interval::Month, 'INTERVAL 1 MONTH'],
    ]);

    it('builds a column breakdown with a top N subquery and an other bucket, all as parameters', function () {
        $built = (new TrendsSqlBuilder)->build(sample(['breakdown' => Breakdown::byColumn(BreakdownColumn::Browser, 3)]));

        expect($built->sql)->toContain('if(bd IN (SELECT bd FROM (SELECT if(empty(toString(browser)), {none:String}, toString(browser)) AS bd, uniqExact(uuid) AS c FROM project_events WHERE ')
            ->and($built->sql)->toContain('GROUP BY bd ORDER BY c DESC, bd ASC LIMIT {top_n:UInt8})), bd, {other:String}) AS breakdown')
            ->and($built->sql)->toContain('GROUP BY series, bucket, breakdown ORDER BY bucket ASC, series ASC, breakdown ASC LIMIT 10001')
            ->and(plain($built->params))->toMatchArray(['top_n' => 3, 'other' => Breakdown::OTHER_LABEL, 'none' => Breakdown::NONE_LABEL])
            ->and($built->params)->not->toHaveKey('bd_key');
    });

    it('builds a property breakdown with the key as a parameter', function () {
        $built = (new TrendsSqlBuilder)->build(sample(['breakdown' => Breakdown::byProperty('$browser', 5)]));

        expect($built->sql)->toContain('JSONExtractString(properties, {bd_key:String})')
            ->and($built->params['bd_key'])->toBe('$browser');
    });

    it('builds each filter operator with the key and value as parameters', function () {
        $built = (new TrendsSqlBuilder)->build(sample(['filters' => [
            new PropertyFilter('a', FilterOperator::Equals, '1'),
            new PropertyFilter('b', FilterOperator::NotEquals, '2'),
            new PropertyFilter('c', FilterOperator::Contains, '3'),
            new PropertyFilter('d', FilterOperator::IsSet),
        ]]));

        expect($built->sql)->toContain('AND JSONExtractString(properties, {f0_key:String}) = {f0_value:String}')
            ->and($built->sql)->toContain('AND JSONExtractString(properties, {f1_key:String}) != {f1_value:String}')
            ->and($built->sql)->toContain('AND positionCaseInsensitiveUTF8(JSONExtractString(properties, {f2_key:String}), {f2_value:String}) > 0')
            ->and($built->sql)->toContain('AND (JSONHas(properties, {f3_key:String}) AND JSONExtractRaw(properties, {f3_key:String}) != {f3_null:String})')
            ->and($built->params)->toMatchArray(['f0_key' => 'a', 'f0_value' => '1', 'f1_key' => 'b', 'f1_value' => '2', 'f2_key' => 'c', 'f2_value' => '3', 'f3_key' => 'd', 'f3_null' => 'null']);
    });

    it('always has the UTC range predicate, an aggregate, a group by and the LIMIT', function (TrendsQuery $q) {
        $sql = (new TrendsSqlBuilder)->build($q)->sql;

        expect($sql)->toContain('timestamp >= {from:DateTime64(3)} AND timestamp < {to:DateTime64(3)}')
            ->and($sql)->toContain('GROUP BY')->and($sql)->toContain('uniqExact(')->and($sql)->toEndWith('LIMIT '.(TrendsQuery::MAX_ROWS + 1));
    })->with([
        'plain' => fn () => sample(),
        'breakdown' => fn () => sample(['breakdown' => Breakdown::byColumn(BreakdownColumn::Event)]),
        'filtered unique' => fn () => sample(['metric' => Metric::UniqueUsers, 'filters' => [new PropertyFilter('a', FilterOperator::IsSet)]]),
    ]);
});

it('asks for one row more than the cap and stays under the profile result limit', function () {
    $sql = (new TrendsSqlBuilder)->build(sample())->sql;

    expect(TrendsQuery::MAX_ROWS + 1)->toBe(10001)->and($sql)->toEndWith('LIMIT 10001')
        ->and(TrendsQuery::MAX_ROWS + 1)->toBeLessThan(20000);
});

describe('what reaches the reader', function () {
    it('passes the reader lint for every shape (no quote, no $, no comment, no table name)', function (TrendsQuery $q) {
        [$fake, $reader] = recordingReader();
        $built = (new TrendsSqlBuilder)->build($q);

        $reader->select(7, $built->sql, $built->params);

        expect($fake->calls)->toHaveCount(1)
            ->and($fake->calls[0]['sql'])->toStartWith('WITH project_events AS (SELECT uuid,')
            ->and($built->sql)->not->toContain("'")->not->toContain('$')->not->toContain('--')->not->toContain(';');
    })->with([
        'plain' => fn () => sample(),
        'column breakdown' => fn () => sample(['breakdown' => Breakdown::byColumn(BreakdownColumn::CurrentUrl)]),
        'property breakdown and all operators' => fn () => sample([
            'metric' => Metric::UniqueUsers,
            'breakdown' => Breakdown::byProperty('plan'),
            'filters' => [
                new PropertyFilter('a', FilterOperator::Equals, 'x'), new PropertyFilter('b', FilterOperator::NotEquals, 'x'),
                new PropertyFilter('c', FilterOperator::Contains, 'x'), new PropertyFilter('d', FilterOperator::IsSet),
            ],
        ]),
        'hourly week' => fn () => sample(['interval' => Interval::Hour, 'to' => '2026-03-02']),
        'month' => fn () => sample(['interval' => Interval::Month, 'from' => '2025-12-01', 'to' => '2026-03-01']),
    ]);

    it('puts only bound parameters in the query: never a value in the SQL text', function () {
        // every value of the definition must be absent from the SQL text and present in the params
        $q = sample([
            'events' => ['UNIQUE_EVENT_NAME'], 'timezone' => 'Asia/Kolkata',
            'breakdown' => Breakdown::byProperty('UNIQUE_BREAKDOWN_KEY'),
            'filters' => [new PropertyFilter('UNIQUE_FILTER_KEY', FilterOperator::Equals, 'UNIQUE_FILTER_VALUE')],
        ]);
        $built = (new TrendsSqlBuilder)->build($q);

        foreach (['UNIQUE_EVENT_NAME', 'Asia/Kolkata', 'UNIQUE_BREAKDOWN_KEY', 'UNIQUE_FILTER_KEY', 'UNIQUE_FILTER_VALUE', '2026'] as $needle) {
            expect($built->sql)->not->toContain($needle);
        }
        expect(json_encode(plain($built->params)))->toContain('UNIQUE_EVENT_NAME', 'Asia\/Kolkata', 'UNIQUE_BREAKDOWN_KEY', 'UNIQUE_FILTER_KEY', 'UNIQUE_FILTER_VALUE');
    });
});

describe('property test: hostile values change only the parameters', function () {
    $hostile = [
        "x' OR '1'='1",
        "'; DROP TABLE posthog.events; --",
        '/* comment */ UNION ALL SELECT 1',
        'SETTINGS SQL_project_id = 999',
        '{evil:String} {project_id:UInt32}',
        '$$dollar$$ $1 ${x}',
        "back\\slash \\' \\\\",
        "tab\there\nnewline\r\n",
        'ünïcödé 日本語 😀 \u{202E}rtl',
        'FORMAT TSV INTO OUTFILE mergeTreeIndex(a, b)',
        "null\0byte",
        'x`; --`',
        str_repeat('A', 190),
    ];

    it('builds the SAME sql text whatever the event names, keys and values are', function () use ($hostile) {
        $shape = function (string $event, string $key, string $value, string $breakdownKey): TrendsQuery {
            return new TrendsQuery(
                [$event, $event.'2'], Metric::UniqueUsers,
                new DateRange(new DateTimeImmutable('2026-03-01 UTC'), new DateTimeImmutable('2026-03-08 UTC')),
                Interval::Week, 'Europe/Istanbul', Breakdown::byProperty($breakdownKey, 7),
                [
                    new PropertyFilter($key, FilterOperator::Equals, $value),
                    new PropertyFilter($key.'b', FilterOperator::NotEquals, $value),
                    new PropertyFilter($key.'c', FilterOperator::Contains, $value),
                    new PropertyFilter($key.'d', FilterOperator::IsSet),
                ],
            );
        };

        $builder = new TrendsSqlBuilder;
        $reference = $builder->build($shape('plain', 'plain', 'plain', 'plain'));
        $paramSets = [];

        foreach ($hostile as $h) {
            $built = $builder->build($shape($h, $h, $h, $h));

            expect($built->sql)->toBe($reference->sql)
                ->and(array_keys($built->params))->toBe(array_keys($reference->params))
                ->and($built->params['f0_value'])->toBe($h)
                ->and($built->params['bd_key'])->toBe($h)
                ->and($built->params['names'][0])->toBe($h);
            $paramSets[] = json_encode(plain($built->params));
        }

        // the parameters really differ from one hostile value to the next (the test is not vacuous)
        expect(array_unique($paramSets))->toHaveCount(count($hostile));
    });

    it('lets every hostile value through the real reader lint, as parameters', function () use ($hostile) {
        [$fake, $reader] = recordingReader();
        foreach ($hostile as $h) {
            $q = new TrendsQuery(
                [$h], Metric::TotalEvents,
                new DateRange(new DateTimeImmutable('2026-03-01 UTC'), new DateTimeImmutable('2026-03-02 UTC')),
                Interval::Hour, 'UTC', Breakdown::byProperty($h),
                [new PropertyFilter($h, FilterOperator::Contains, $h)],
            );
            $built = (new TrendsSqlBuilder)->build($q);
            $reader->select(1, $built->sql, $built->params);
        }

        expect($fake->calls)->toHaveCount(count($hostile));
    });

    it('keeps the SQL text identical for the same shape whatever the dates and the zone are', function () {
        $builder = new TrendsSqlBuilder;
        $a = $builder->build(sample(['timezone' => 'UTC', 'from' => '2026-03-01', 'to' => '2026-03-08']));
        $b = $builder->build(sample(['timezone' => 'Pacific/Chatham', 'from' => '2025-07-04', 'to' => '2025-07-30']));

        expect($a->sql)->toBe($b->sql)->and(plain($a->params))->not->toBe(plain($b->params));
    });

    it('changes the SQL text only with the shape: metric, interval, breakdown kind, filter operators', function () {
        $builder = new TrendsSqlBuilder;
        $texts = [
            $builder->build(sample())->sql,
            $builder->build(sample(['metric' => Metric::UniqueUsers]))->sql,
            $builder->build(sample(['interval' => Interval::Week]))->sql,
            $builder->build(sample(['breakdown' => Breakdown::byColumn(BreakdownColumn::Browser)]))->sql,
            $builder->build(sample(['breakdown' => Breakdown::byProperty('x')]))->sql,
            $builder->build(sample(['filters' => [new PropertyFilter('x', FilterOperator::Equals, 'y')]]))->sql,
            $builder->build(sample(['filters' => [new PropertyFilter('x', FilterOperator::IsSet)]]))->sql,
        ];

        expect(array_unique($texts))->toHaveCount(count($texts));
    });
});

describe('parameter binder', function () {
    it('hands out typed placeholders and rejects bad names, duplicates and values that do not fit', function () {
        $p = new Params;

        expect($p->bind('a', ParamType::Text, 'x'))->toBe('{a:String}')
            ->and($p->bind('n', ParamType::SmallCount, 255))->toBe('{n:UInt8}')
            ->and($p->all())->toBe(['a' => 'x', 'n' => 255]);
        expect(fn () => $p->bind('a', ParamType::Text, 'y'))->toThrow(InvalidArgumentException::class, 'already bound')
            ->and(fn () => $p->bind('Bad Name', ParamType::Text, 'y'))->toThrow(InvalidArgumentException::class, 'Invalid parameter name')
            ->and(fn () => $p->bind('x}', ParamType::Text, 'y'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $p->bind('big', ParamType::SmallCount, 256))->toThrow(InvalidArgumentException::class, 'does not fit')
            ->and(fn () => $p->bind('neg', ParamType::SmallCount, -1))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $p->bind('s', ParamType::Text, 5))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $p->bind('l', ParamType::TextList, []))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $p->bind('l2', ParamType::TextList, ['a', 1]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $p->bind('d', ParamType::Instant, '2026-03-01'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $p->bind('u', ParamType::Text, "\xB1"))->toThrow(InvalidArgumentException::class);
    });

    it('offers only fixed ClickHouse type strings', function () {
        expect(array_map(fn ($c) => $c->value, ParamType::cases()))->toBe(['String', 'UInt8', 'Array(String)', 'DateTime64(3)']);
    });
});
