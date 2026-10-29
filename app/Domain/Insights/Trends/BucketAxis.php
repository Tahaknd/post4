<?php

namespace App\Domain\Insights\Trends;

use App\Domain\Insights\Query\DateRange;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The bucket starts a trends query covers, as UTC epoch seconds, computed the way toStartOfInterval does it
 * in ClickHouse for the project zone (hour: local hour, day: local midnight, week: local Monday midnight,
 * month: local first day). Used to fill empty buckets with zero and to bound the result size BEFORE the
 * query runs. The clickhouse group (BucketAxisOracleTest) compares it with the real toStartOfInterval.
 *
 * DAY, WEEK, MONTH are computed from the LOCAL CALENDAR DATE, bucket by bucket, never by adding to the
 * previous bucket: a local midnight that does not exist (a DST gap at midnight: Santiago, Sao Paulo, Havana,
 * Cairo, Beirut ...) starts that day at the transition instant (the first instant of the local day, which is
 * what ClickHouse returns), and the next bucket is again a real local midnight instead of being shifted to
 * 01:00.
 *
 * HOUR works on epoch seconds: each step re-floors to the local hour at the instant it lands on, using the
 * zone offset THAT instant has. In the repeated hour of a fall-back (Dublin, Casablanca, El_Aaiun) both
 * occurrences are kept, because the instant, not the local label, identifies a bucket (labels in TrendsResult
 * carry the offset).
 *
 * KNOWN LIMITS: Australia/Lord_Howe (DST shift of 30 minutes): day, week and month agree with ClickHouse, the
 * hourly axis does not after the change (ClickHouse stays on the half hour UTC grid; documented and pinned in
 * BucketAxisOracleTest). Every other PHP zone agreed with ClickHouse 25.8 over a spring and an autumn window of 2023
 * (BucketAxisAllZonesTest, group slow, `composer test:slow`), Africa/Casablanca's Ramadan shift included; PHP and ClickHouse each ship their own tzdata, so a
 * zone whose rules changed recently can differ until both are updated. {@see TrendsResult::fromRows()} never
 * invents or drops a bucket because of such a difference: it aligns on the buckets ClickHouse returned.
 */
final class BucketAxis
{
    /** @return list<int> */
    public static function starts(DateRange $range, Interval $interval, DateTimeZone $zone): array
    {
        return $interval === Interval::Hour
            ? self::hourStarts($range, $zone)
            : self::calendarStarts($range, $interval, $zone);
    }

    /** @return list<int> */
    private static function hourStarts(DateRange $range, DateTimeZone $zone): array
    {
        $to = $range->to->getTimestamp();
        $floor = function (int $t) use ($zone): int {
            $local = $t + $zone->getOffset(new DateTimeImmutable('@'.$t));

            return $t - ((($local % 3600) + 3600) % 3600);
        };

        $starts = [];
        $cursor = $floor($range->from->getTimestamp());
        while ($cursor < $to) {
            $starts[] = $cursor;
            $next = $floor($cursor + 3600);
            if ($next <= $cursor) {
                // The floor stepped back (an offset change inside the hour): move on by half an hour.
                $next = $cursor + 1800;
            }
            $cursor = $next;
        }

        return $starts;
    }

    /** @return list<int> */
    private static function calendarStarts(DateRange $range, Interval $interval, DateTimeZone $zone): array
    {
        $utc = new DateTimeZone('UTC');
        $to = $range->to->getTimestamp();
        // Local calendar date of `from`, as a plain UTC date so the arithmetic below knows no DST.
        $date = new DateTimeImmutable($range->from->setTimezone($zone)->format('Y-m-d'), $utc);
        $first = match ($interval) {
            Interval::Day => $date,
            Interval::Week => $date->modify('-'.((int) $date->format('N') - 1).' days'),
            Interval::Month => $date->modify('first day of this month'),
            Interval::Hour => throw new \LogicException('hours are handled separately'),
        };

        $starts = [];
        for ($i = 0; ; $i++) {
            $day = match ($interval) {
                Interval::Day => $first->modify("+{$i} days"),
                Interval::Week => $first->modify('+'.(7 * $i).' days'),
                Interval::Month => $first->modify("+{$i} months"),
                Interval::Hour => throw new \LogicException('hours are handled separately'),
            };
            $start = self::firstInstantOfLocalDate($day->format('Y-m-d'), $zone);
            if ($start >= $to) {
                return $starts;
            }
            $starts[] = $start;
        }
    }

    /**
     * The EARLIEST instant whose local date is `$date`: a missing local midnight (DST gap) resolves to the end of
     * the gap, and a local midnight that happens twice (the clocks go back across midnight: Havana, or Antarctica/
     * Casey on 2023-03-09, which went back three hours) resolves to its FIRST occurrence, which is what
     * toStartOfInterval returns. PHP gives some instant with that date, then the loop walks back in quarter hours
     * while the instant before it is still on the same local date.
     */
    private static function firstInstantOfLocalDate(string $date, DateTimeZone $zone): int
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone)->getTimestamp();

        // Bounded: a local date is at most a day and a few hours long.
        for ($i = 0; $i < 112; $i++) {
            $before = (new DateTimeImmutable('@'.($start - 900)))->setTimezone($zone);
            if ($before->format('Y-m-d') !== $date) {
                break;
            }
            $start -= 900;
        }

        return $start;
    }
}
