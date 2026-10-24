<?php

namespace App\Domain\Insights\Trends;

use App\Infrastructure\ClickHouse\ProjectScopedReader;

/**
 * Columns of the scoped events relation a breakdown may group by (an allow list; every case must be one of
 * ProjectScopedReader::COLUMNS, which a test and Breakdown's constructor check). `uuid`, `properties`,
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
        assert(in_array($this->value, ProjectScopedReader::COLUMNS, true));

        return 'toString('.$this->value.')';
    }
}
