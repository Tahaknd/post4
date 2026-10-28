<?php

namespace App\Domain\Insights\Trends;

/**
 * Columns of the scoped events relation a breakdown may group by (an allow list; every case must be one of
 * ProjectScopedReader::COLUMNS, which TrendsQueryTest checks). `uuid`, `properties`,
 * `timestamp`, `created_at` and `project_id` are deliberately absent (unbounded or meaningless groups).
 * `os`, `device_type` and `utm_*` are MATERIALIZED from JSON properties with JSONExtractString (migration 003),
 * which STRINGIFIES non-strings: an SDK that sent `$os: 5` is grouped under '5'. A missing or empty value is the
 * 'None' bucket of the breakdown.
 */
enum BreakdownColumn: string
{
    case Event = 'event';
    case DistinctId = 'distinct_id';
    case Browser = 'browser';
    case CountryCode = 'country_code';
    case CurrentUrl = 'current_url';
    case Os = 'os';
    case DeviceType = 'device_type';
    case UtmSource = 'utm_source';
    case UtmMedium = 'utm_medium';
    case UtmCampaign = 'utm_campaign';

    /** The name the page shows for the column. Exhaustive: a new case does not compile into a label-less option. */
    public function label(): string
    {
        return match ($this) {
            self::Event => 'Event name',
            self::DistinctId => 'User (distinct id)',
            self::Browser => 'Browser',
            self::CountryCode => 'Country',
            self::CurrentUrl => 'Current URL',
            self::Os => 'Operating system',
            self::DeviceType => 'Device type',
            self::UtmSource => 'UTM source',
            self::UtmMedium => 'UTM medium',
            self::UtmCampaign => 'UTM campaign',
        };
    }

    /** Fixed SQL for the raw value (the column name comes from this enum, which is code). */
    public function valueSql(): string
    {
        return 'toString('.$this->value.')';
    }
}
