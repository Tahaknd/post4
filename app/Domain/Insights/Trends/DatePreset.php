<?php

namespace App\Domain\Insights\Trends;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Relative date ranges, resolved on the SERVER in a time zone (the project's by default) to WHOLE local days
 * (the window starts at a local midnight and ends at a local midnight, today included). Resolving here, instead of
 * letting the client send "now minus 7 days", is what makes a relative range cacheable: every request of the same
 * local day produces the very same canonical definition, hence the same hash and the same cache key; the key
 * changes exactly when the local day rolls over.
 *
 *  - last_7_days / last_30_days / last_90_days: the N local days ending with today (today is partial);
 *  - this_month: the first of the month up to today;
 *  - last_month: the whole previous calendar month.
 */
enum DatePreset: string
{
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case Last90Days = 'last_90_days';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';

    public function resolve(DateTimeInterface $now, DateTimeZone $zone): DateRange
    {
        // Date arithmetic on plain local dates (in UTC, where every day has 24 hours), then DateRange::localDays
        // turns the two dates into local midnights of the zone.
        $today = new DateTimeImmutable(DateTimeImmutable::createFromInterface($now)->setTimezone($zone)->format('Y-m-d').' UTC');

        [$first, $last] = match ($this) {
            self::Last7Days => [$today->modify('-6 days'), $today],
            self::Last30Days => [$today->modify('-29 days'), $today],
            self::Last90Days => [$today->modify('-89 days'), $today],
            self::ThisMonth => [$today->modify('first day of this month'), $today],
            self::LastMonth => [
                $today->modify('first day of last month'),
                $today->modify('first day of this month')->modify('-1 day'),
            ],
        };

        return DateRange::localDays($first->format('Y-m-d'), $last->format('Y-m-d'), $zone);
    }
}
