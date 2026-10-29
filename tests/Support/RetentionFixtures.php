<?php

namespace Tests\Support;

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Query\EventCondition;
use App\Domain\Insights\Query\PropertyFilter;
use App\Domain\Insights\Retention\RetentionPeriod;
use App\Domain\Insights\Retention\RetentionQuery;
use App\Domain\Insights\Retention\RetentionType;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/** Factories and helpers shared by the retention tests. */
final class RetentionFixtures
{
    private function __construct() {}

    /**
     * A retention query. `$target` and `$returning` are event names or `[event, [PropertyFilter, ...]]`. The range is given
     * as local days of the zone: `from` inclusive, `to` EXCLUSIVE (`YYYY-MM-DD`, the start of that day).
     *
     * @param  string|array{0: string, 1: list<PropertyFilter>}  $target
     * @param  string|array{0: string, 1: list<PropertyFilter>}  $returning
     * @param  array{period?: RetentionPeriod, periods?: int, type?: RetentionType, from?: string, to?: string, zone?: string}  $o
     */
    public static function query(string|array $target = 'visit', string|array $returning = 'visit', array $o = []): RetentionQuery
    {
        $zone = $o['zone'] ?? 'UTC';
        $tz = new DateTimeZone($zone);
        $condition = fn ($c) => is_array($c) ? new EventCondition($c[0], $c[1]) : new EventCondition($c);

        return new RetentionQuery(
            $condition($target),
            $condition($returning),
            $o['period'] ?? RetentionPeriod::Day,
            $o['periods'] ?? 3,
            new DateRange(new DateTimeImmutable($o['from'] ?? '2026-03-02', $tz), new DateTimeImmutable($o['to'] ?? '2026-03-09', $tz)),
            $zone,
            $o['type'] ?? RetentionType::FirstTime,
        );
    }

    public static function runner(ClickHouseReads $client, ?Repository $cache = null): InsightRunner
    {
        return new InsightRunner(new ProjectScopedReader($client), $cache ?? new Repository(new ArrayStore));
    }

    /**
     * Rows of the retention SQL: `[cohort => [size, [retained...]]]`.
     *
     * @param  array<int, array{0: int, 1: list<int>}>  $cohorts
     */
    public static function rows(array $cohorts): array
    {
        $rows = [];
        foreach ($cohorts as $cohort => [$size, $retained]) {
            $rows[] = ['cohort' => $cohort, 'size' => $size, 'retained' => $retained];
        }

        return $rows;
    }
}
