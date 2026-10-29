<?php

namespace Tests\Support;

use App\Domain\Insights\Funnel\ConversionWindow;
use App\Domain\Insights\Funnel\FunnelOrder;
use App\Domain\Insights\Funnel\FunnelQuery;
use App\Domain\Insights\Funnel\FunnelResult;
use App\Domain\Insights\Funnel\FunnelStep;
use App\Domain\Insights\Funnel\WindowUnit;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Query\PropertyFilter;
use App\Infrastructure\ClickHouse\ClickHouseReads;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/** Factories and helpers shared by the funnel tests. */
final class FunnelFixtures
{
    private function __construct() {}

    /**
     * A funnel over `$steps`: event names, or `[event, [PropertyFilter, ...]]` for a step with filters.
     * Defaults: range 2026-03-10..2026-03-13 UTC (three days, `to` exclusive), window 1 day, ordered.
     *
     * @param  list<string|array{0: string, 1: list<PropertyFilter>}>  $steps
     * @param  array{from?: string, to?: string, window?: array{0: int, 1: WindowUnit}, order?: FunnelOrder}  $o
     */
    public static function query(array $steps, array $o = []): FunnelQuery
    {
        [$value, $unit] = $o['window'] ?? [1, WindowUnit::Day];

        return new FunnelQuery(
            array_map(fn ($s) => is_array($s) ? new FunnelStep($s[0], $s[1]) : new FunnelStep($s), $steps),
            new ConversionWindow($value, $unit),
            new DateRange(new DateTimeImmutable(($o['from'] ?? '2026-03-10').' UTC'), new DateTimeImmutable(($o['to'] ?? '2026-03-13').' UTC')),
            $o['order'] ?? FunnelOrder::Ordered,
        );
    }

    public static function runner(ClickHouseReads $client, ?Repository $cache = null): InsightRunner
    {
        return new InsightRunner(new ProjectScopedReader($client), $cache ?? new Repository(new ArrayStore));
    }

    /** The `count` of every step, in order. @return list<int> */
    public static function counts(FunnelResult $result): array
    {
        return array_map(fn (array $step) => $step['count'], $result->steps);
    }

    /** Rows of the funnel SQL for `[level => persons]`. */
    public static function levelRows(array $levels): array
    {
        $rows = [];
        foreach ($levels as $level => $persons) {
            $rows[] = ['level' => $level, 'people' => $persons];
        }

        return $rows;
    }
}
