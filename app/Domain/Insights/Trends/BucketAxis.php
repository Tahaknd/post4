<?php

namespace App\Domain\Insights\Trends;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The bucket starts a trends query covers, as UTC epoch seconds, computed the way toStartOfInterval does it
 * in ClickHouse for the project zone (hour: local hour, day: local midnight, week: local Monday midnight,
 * month: local first day). Used to fill empty buckets with zero and to bound the result size BEFORE the
 * query runs; the two-tenant tests compare it with what ClickHouse returns.
 */
final class BucketAxis
{
    /** @return list<int> */
    public static function starts(DateRange $range, Interval $interval, DateTimeZone $zone): array
    {
        $to = $range->to->getTimestamp();
        $cursor = self::floor($range->from->setTimezone($zone), $interval);
        $starts = [];

        while ($cursor->getTimestamp() < $to) {
            $starts[] = $cursor->getTimestamp();
            $cursor = self::next($cursor, $interval);
        }

        return $starts;
    }

    private static function floor(DateTimeImmutable $at, Interval $interval): DateTimeImmutable
    {
        return match ($interval) {
            // Arithmetic, not setTime(): in the repeated hour of a DST fall-back setTime() jumps to the first
            // occurrence. Local hour start = local time rounded down, which also covers :30 and :45 zones.
            Interval::Hour => self::floorToLocalHour($at),
            Interval::Day => $at->setTime(0, 0),
            Interval::Week => $at->modify('monday this week')->setTime(0, 0),
            Interval::Month => $at->modify('first day of this month')->setTime(0, 0),
        };
    }

    private static function floorToLocalHour(DateTimeImmutable $at): DateTimeImmutable
    {
        $offset = $at->getOffset();
        $local = $at->getTimestamp() + $offset;

        return $at->setTimestamp($local - ((($local % 3600) + 3600) % 3600) - $offset);
    }

    private static function next(DateTimeImmutable $at, Interval $interval): DateTimeImmutable
    {
        return match ($interval) {
            Interval::Hour => $at->setTimestamp($at->getTimestamp() + 3600),
            Interval::Day => $at->modify('+1 day'),
            Interval::Week => $at->modify('+1 week'),
            Interval::Month => $at->modify('+1 month'),
        };
    }
}
