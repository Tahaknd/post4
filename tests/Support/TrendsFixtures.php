<?php

namespace Tests\Support;

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsResult;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * Factories and small helpers shared by the trends tests. Every factory takes an override array with the keys
 * events, metric, interval, timezone, breakdown, filters and either range (a DateRange) or from / to (UTC dates).
 */
final class TrendsFixtures
{
    private function __construct() {}

    /** A UTC window; `from` inclusive, `to` exclusive. */
    public static function window(string $from, string $to): DateRange
    {
        return new DateRange(new DateTimeImmutable($from.' UTC'), new DateTimeImmutable($to.' UTC'));
    }

    /** Defaults: `signup`, total events, 2026-03-01..2026-03-08, day, UTC. */
    public static function query(array $o = []): TrendsQuery
    {
        return self::build(['events' => ['signup'], 'from' => '2026-03-01', 'to' => '2026-03-08'], $o);
    }

    /** Defaults of the SQL builder tests: events a and b, 2026-03-01..2026-03-08, day, Europe/Istanbul. */
    public static function sample(array $o = []): TrendsQuery
    {
        return self::build(['events' => ['a', 'b'], 'timezone' => 'Europe/Istanbul', 'from' => '2026-03-01', 'to' => '2026-03-08'], $o);
    }

    /** Defaults of the integration tests: `signup`, 2026-03-14..2026-03-18, day, UTC. */
    public static function integration(array $o = []): TrendsQuery
    {
        return self::build(['events' => ['signup'], 'from' => '2026-03-14', 'to' => '2026-03-18'], $o);
    }

    /** Defaults of the runner tests: one event, total, 3 days (2026-03-01..2026-03-04), UTC. */
    public static function definition(string $event = 'signup', ?Breakdown $breakdown = null, string $from = '2026-03-01', string $to = '2026-03-04'): TrendsQuery
    {
        return self::build([], ['events' => [$event], 'breakdown' => $breakdown, 'from' => $from, 'to' => $to]);
    }

    /** An InsightRunner over `$client` (through the real ProjectScopedReader) and an in-memory cache unless one is given. */
    public static function runner(ClickHouseReads $client, ?Repository $cache = null): InsightRunner
    {
        return new InsightRunner(new ProjectScopedReader($client), $cache ?? new Repository(new ArrayStore));
    }

    /** Rows for the 3 day window of {@see self::definition()}: 4 events on day 1, 9 on day 3. */
    public static function dayRows(string $event = 'signup'): array
    {
        return [
            ['series' => $event, 'bucket' => strtotime('2026-03-01 UTC'), 'value' => 4],
            ['series' => $event, 'bucket' => strtotime('2026-03-03 UTC'), 'value' => 9],
        ];
    }

    /**
     * `$count` cells over 3 daily buckets, one breakdown value per 3 cells.
     *
     * @return list<array<string, mixed>>
     */
    public static function cells(int $count): array
    {
        $day = strtotime('2026-03-01 UTC');
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['series' => 'signup', 'bucket' => $day + 86400 * ($i % 3), 'breakdown' => 'v'.intdiv($i, 3), 'value' => 1];
        }

        return $rows;
    }

    /** Parameters with instants as strings, for readable comparisons. */
    public static function plain(array $params): array
    {
        return array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s.v') : $v, $params);
    }

    /** @return array<string, list<int>> `event` or `event|breakdown` => data */
    public static function dataOf(TrendsResult $result): array
    {
        $out = [];
        foreach ($result->series as $s) {
            $out[$s['event'].($s['breakdown'] === null ? '' : '|'.$s['breakdown'])] = $s['data'];
        }

        return $out;
    }

    private static function build(array $defaults, array $o): TrendsQuery
    {
        $a = $o + $defaults + [
            'metric' => Metric::TotalEvents, 'interval' => Interval::Day, 'timezone' => 'UTC', 'breakdown' => null, 'filters' => [],
        ];
        $range = $a['range'] ?? self::window($a['from'], $a['to']);

        return new TrendsQuery($a['events'], $a['metric'], $range, $a['interval'], $a['timezone'], $a['breakdown'], $a['filters']);
    }
}
