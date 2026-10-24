<?php

namespace App\Domain\Insights\Trends;

/** What a series counts. The aggregate is a fixed SQL fragment, never built from input. */
enum Metric: string
{
    /** Events, deduplicated by uuid (ReplacingMergeTree only collapses duplicates in background merges). */
    case TotalEvents = 'total_events';

    /** Distinct `distinct_id` values (person based counting arrives with the identity milestone). */
    case UniqueUsers = 'unique_users';

    public function aggregateSql(): string
    {
        return match ($this) {
            self::TotalEvents => 'uniqExact(uuid)',
            self::UniqueUsers => 'uniqExact(distinct_id)',
        };
    }
}
