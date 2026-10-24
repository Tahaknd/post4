<?php

namespace App\Domain\Insights\Trends;

/**
 * Columns of the scoped events relation a breakdown may group by (an allow list; every case must be one of
 * ProjectScopedReader::COLUMNS, which TrendsQueryTest checks). `uuid`, `properties`,
 * `timestamp`, `created_at` and `project_id` are deliberately absent (unbounded or meaningless groups).
 */
enum BreakdownColumn: string
{
    case Event = 'event';
    case DistinctId = 'distinct_id';
    case Browser = 'browser';
    case CountryCode = 'country_code';
    case CurrentUrl = 'current_url';

    /** Fixed SQL for the raw value (the column name comes from this enum, which is code). */
    public function valueSql(): string
    {
        return 'toString('.$this->value.')';
    }
}
