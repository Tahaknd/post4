<?php

namespace App\Domain\Insights\Funnel;

/** Unit of the conversion window. Fixed lengths: a day is 86400 seconds, whatever the zone (the window is a duration). */
enum WindowUnit: string
{
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';

    public function seconds(): int
    {
        return match ($this) {
            self::Minute => 60,
            self::Hour => 3600,
            self::Day => 86400,
            self::Week => 604800,
        };
    }
}
