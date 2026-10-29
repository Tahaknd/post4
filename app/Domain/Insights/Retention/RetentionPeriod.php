<?php

namespace App\Domain\Insights\Retention;

use App\Domain\Insights\Trends\BucketAxis;
use App\Domain\Insights\Trends\Interval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The length of a retention period: a calendar day, an ISO week (starts on MONDAY, like the weekly buckets of trends) or a
 * calendar month, always in the zone of the query (the project's by default). The boundaries are the ones trends uses, so
 * "week 10" means the same days in both.
 *
 * The SQL side numbers the periods with ClickHouse's `toRelative{Day,Week,Month}Num(time, zone)`: a counter that grows by one at
 * every local period boundary (daylight saving time included), so the distance between two periods is a subtraction
 * and no string unit has to appear in the SQL. The PHP side builds the matching start instants ({@see self::starts()});
 * the clickhouse group compares both over many zones.
 */
enum RetentionPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** The trends bucket of the same length (its axis is the axis of the cohorts). */
    public function interval(): Interval
    {
        return match ($this) {
            self::Day => Interval::Day,
            self::Week => Interval::Week,
            self::Month => Interval::Month,
        };
    }

    /** The ClickHouse function that numbers the periods (a fixed fragment, never from input). */
    public function numberFunction(): string
    {
        return match ($this) {
            self::Day => 'toRelativeDayNum',
            self::Week => 'toRelativeWeekNum',
            self::Month => 'toRelativeMonthNum',
        };
    }

    /**
     * The first instants (UTC epoch seconds) of `$count` consecutive periods, the first one being the period that contains
     * `$instant`. Computed from the LOCAL calendar date, period by period (never by adding seconds), exactly like
     * {@see BucketAxis}: a local midnight that does not exist starts the period at the end of the gap.
     *
     * @return list<int>
     */
    public function starts(DateTimeImmutable $instant, int $count, DateTimeZone $zone): array
    {
        $date = new DateTimeImmutable($instant->setTimezone($zone)->format('Y-m-d'), new DateTimeZone('UTC'));
        $first = match ($this) {
            self::Day => $date,
            self::Week => $date->modify('-'.((int) $date->format('N') - 1).' days'),
            self::Month => $date->modify('first day of this month'),
        };

        $starts = [];
        for ($i = 0; $i < $count; $i++) {
            $day = match ($this) {
                self::Day => $first->modify("+{$i} days"),
                self::Week => $first->modify('+'.(7 * $i).' days'),
                self::Month => $first->modify("+{$i} months"),
            };
            $starts[] = BucketAxis::firstInstantOfLocalDate($day->format('Y-m-d'), $zone);
        }

        return $starts;
    }
}
