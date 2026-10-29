<?php

namespace Tests\Support;

use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Trends\BucketAxis;
use App\Domain\Insights\Trends\Interval;
use App\Infrastructure\ClickHouse\ClickHouseClient;
use DateTimeZone;

/**
 * ClickHouse as the oracle for {@see BucketAxis}: samples the window every 5 minutes (shorter than any bucket,
 * Lord_Howe's 30 minute shift included) and asks the server which bucket each instant belongs to, with the
 * very expression the trends SQL uses (the interval fragments come from the Interval enum, never from input).
 */
final class AxisOracle
{
    private const SAMPLE_SECONDS = 300;

    /** @return list<int> distinct bucket starts (UTC epoch seconds) the server returns for the window */
    public static function buckets(ClickHouseClient $admin, DateRange $range, Interval $interval, string $zone): array
    {
        $from = $range->from->getTimestamp();
        $samples = intdiv($range->to->getTimestamp() - $from - 1, self::SAMPLE_SECONDS) + 1;
        $fragment = $interval->intervalSql();

        $rows = $admin->select(
            "SELECT DISTINCT toUnixTimestamp(toDateTime(toStartOfInterval(toDateTime64({from:UInt32} + number * {step:UInt32}, 3, 'UTC'), {$fragment}, {tz:String}), {tz:String})) AS bucket "
            .'FROM numbers({n:UInt32}) ORDER BY bucket',
            ['from' => $from, 'step' => self::SAMPLE_SECONDS, 'tz' => $zone, 'n' => $samples],
        );

        return array_map(fn (array $row) => (int) $row['bucket'], $rows);
    }

    /**
     * Differences between the PHP axis and the server, empty when they agree.
     *
     * @return array{only_php: list<string>, only_clickhouse: list<string>}
     */
    public static function diff(ClickHouseClient $admin, DateRange $range, Interval $interval, string $zone): array
    {
        $php = BucketAxis::starts($range, $interval, new DateTimeZone($zone));
        $server = self::buckets($admin, $range, $interval, $zone);
        $fmt = fn (int $t) => gmdate('Y-m-d H:i', $t).'Z';

        return [
            'only_php' => array_map($fmt, array_values(array_diff($php, $server))),
            'only_clickhouse' => array_map($fmt, array_values(array_diff($server, $php))),
        ];
    }
}
