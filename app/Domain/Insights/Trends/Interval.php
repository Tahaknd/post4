<?php

namespace App\Domain\Insights\Trends;

/** Bucket size. Each case maps to a fixed SQL fragment; the interval is never interpolated from input. */
enum Interval: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** Argument of toStartOfInterval. Weeks start on Monday, in the project time zone. */
    public function intervalSql(): string
    {
        return match ($this) {
            self::Hour => 'INTERVAL 1 HOUR',
            self::Day => 'INTERVAL 1 DAY',
            self::Week => 'INTERVAL 1 WEEK',
            self::Month => 'INTERVAL 1 MONTH',
        };
    }
}
