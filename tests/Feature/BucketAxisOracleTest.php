<?php

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Trends\BucketAxis;
use App\Domain\Insights\Trends\DateRange;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsSqlBuilder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\Support\AxisOracle;
use Tests\Support\ClickHouseIntegration as CH;

/*
 * BucketAxis (PHP) against toStartOfInterval (ClickHouse 25.8) as the oracle, see Tests\Support\AxisOracle.
 * Opt-in (group "clickhouse"). The exhaustive all-zones comparison lives in BucketAxisAllZonesTest (group "slow").
 */

pest()->group('clickhouse');

beforeEach(function () {
    config(['insights.cache_ttl_seconds' => 0]);
    $this->ch = CH::connect();
    $this->ch->seedTrendsOnce();
});

afterAll(function () {
    CH::cleanupTrendsAfterAll();
});

it('keeps both occurrences of the repeated hour in Dublin (fall-back 2023-10-29: 73 hourly buckets over three local days)', function () {
    $zone = new DateTimeZone('Europe/Dublin');
    $range = DateRange::localDays('2023-10-28', '2023-10-30', $zone);

    $axis = BucketAxis::starts($range, Interval::Hour, $zone);

    // 24 + 25 + 24 hours, and the 01:00 local hour of the 29th appears twice (00:00Z and 01:00Z)
    expect($axis)->toHaveCount(73)
        ->and($axis)->toContain(strtotime('2023-10-29 00:00:00 UTC'), strtotime('2023-10-29 01:00:00 UTC'))
        ->and($axis)->toBe(AxisOracle::buckets($this->ch->admin, $range, Interval::Hour, 'Europe/Dublin'));
});

it('does not shift the buckets after a missing local midnight (Santiago 2023-09-03) and counts events on both sides of the gap', function () {
    $zone = new DateTimeZone('America/Santiago');
    $range = DateRange::localDays('2023-09-01', '2023-09-06', $zone);
    $q = new TrendsQuery(['visit'], Metric::TotalEvents, $range, Interval::Day, 'America/Santiago');

    $axis = $q->buckets();
    $runner = new InsightRunner($this->ch->reader, new TrendsSqlBuilder, new Repository(new ArrayStore));
    $result = $runner->trends(CH::TREND_D, $q);

    // Sep 3 starts at the transition instant 04:00Z (local midnight does not exist), Sep 4 at a REAL local
    // midnight, 03:00Z (-03): a chained "+1 day" from 01:00 local would give 04:00Z for Sep 4.
    expect(array_map(fn ($t) => gmdate('m-d H:i', $t), array_slice($axis, 0, 5)))
        ->toBe(['09-01 04:00', '09-02 04:00', '09-03 04:00', '09-04 03:00', '09-05 03:00'])
        ->and($axis)->toBe(AxisOracle::buckets($this->ch->admin, $range, Interval::Day, 'America/Santiago'))
        ->and($result->buckets)->toBe($axis)
        // Sep 2 23:30 local, Sep 3 01:30 local, Sep 4 09:00 local: one event each, none lost to the gap
        ->and($result->series[0]['data'])->toBe([0, 1, 1, 1, 0, 0])
        ->and($result->labels[2])->toBe('2023-09-03T01:00:00-03:00');
});

it('agrees with toStartOfInterval for a set of DST zones, every interval, around their transitions', function (string $zone, string $from, string $to) {
    $range = new DateRange(new DateTimeImmutable($from.' UTC'), new DateTimeImmutable($to.' UTC'));

    foreach (Interval::cases() as $interval) {
        $diff = AxisOracle::diff($this->ch->admin, $range, $interval, $zone);

        expect($diff)->toBe(['only_php' => [], 'only_clickhouse' => []], "{$zone} {$interval->value} {$from}..{$to}");
    }
})->with([
    // both transitions of the northern zones in 2023 (spring window, autumn window) and the southern ones
    'New York spring' => ['America/New_York', '2023-03-01', '2023-04-15'],
    'New York autumn' => ['America/New_York', '2023-10-15', '2023-11-20'],
    'Berlin spring' => ['Europe/Berlin', '2023-03-01', '2023-04-15'],
    'Berlin autumn' => ['Europe/Berlin', '2023-10-15', '2023-11-20'],
    'Dublin spring' => ['Europe/Dublin', '2023-03-01', '2023-04-15'],
    'Dublin autumn' => ['Europe/Dublin', '2023-10-15', '2023-11-20'],
    'Santiago winter to summer' => ['America/Santiago', '2023-08-15', '2023-10-01'],
    'Santiago summer to winter' => ['America/Santiago', '2023-03-20', '2023-04-20'],
    'Sao Paulo (DST abolished 2019)' => ['America/Sao_Paulo', '2018-10-25', '2018-11-20'],
    'Sao Paulo 2018 end of DST' => ['America/Sao_Paulo', '2018-02-01', '2018-02-28'],
    'Havana spring (midnight gap)' => ['America/Havana', '2023-03-01', '2023-04-01'],
    'Cairo 2023 (DST back after 2014)' => ['Africa/Cairo', '2023-04-15', '2023-05-10'],
    'Beirut (midnight gap)' => ['Asia/Beirut', '2023-03-15', '2023-04-05'],
    'Casablanca Ramadan 2023' => ['Africa/Casablanca', '2023-03-10', '2023-04-30'],
    'Kolkata (no DST, half hour offset)' => ['Asia/Kolkata', '2023-03-01', '2023-03-20'],
    'Kathmandu (+05:45)' => ['Asia/Kathmandu', '2023-03-01', '2023-03-20'],
    'Istanbul (no DST since 2016)' => ['Europe/Istanbul', '2023-03-01', '2023-04-15'],
]);

it('documents Australia/Lord_Howe: day, week and month agree, the hourly grid differs by 30 minutes after the DST start', function () {
    $range = new DateRange(new DateTimeImmutable('2023-09-30 12:00 UTC'), new DateTimeImmutable('2023-10-01 06:00 UTC'));

    $none = ['only_php' => [], 'only_clickhouse' => []];
    foreach ([Interval::Day, Interval::Week, Interval::Month] as $interval) {
        expect(AxisOracle::diff($this->ch->admin, $range, $interval, 'Australia/Lord_Howe'))->toBe($none, $interval->value);
    }

    // Before the change (+10:30) both use the :30 UTC grid. After it (+11:00) PHP moves to the :00 UTC grid, which
    // is the local hour, while ClickHouse STAYS on :30. The result then follows ClickHouse (TrendsResult::fromRows),
    // so the chart has the buckets the data really lives in.
    $hour = AxisOracle::diff($this->ch->admin, $range, Interval::Hour, 'Australia/Lord_Howe');
    expect($hour['only_php'])->toHaveCount(15)->and($hour['only_clickhouse'])->toHaveCount(15)
        ->and(array_map(fn ($t) => gmdate('Y-m-d H:i', strtotime($t) + 1800).'Z', $hour['only_php']))->toBe($hour['only_clickhouse']);
});
