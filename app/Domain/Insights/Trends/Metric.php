<?php

namespace App\Domain\Insights\Trends;

use App\Domain\Insights\Sql\PersonSource;

/** What a series counts. The aggregate is a fixed SQL fragment, never built from input. */
enum Metric: string
{
    /** Events, deduplicated by uuid (ReplacingMergeTree only collapses duplicates in background merges). */
    case TotalEvents = 'total_events';

    /**
     * Distinct PERSONS (Milestone 4): an event counts for the person its distinct id is mapped to, and for the distinct
     * id itself when that id has no mapping. See {@see PersonSource} for the join and the semantics.
     */
    case UniqueUsers = 'unique_users';

    /**
     * Whether the aggregate reads the person columns of {@see PersonSource::SQL}. Only then does the
     * query join the mapping; the total-events SQL never pays for it.
     */
    public function needsPersons(): bool
    {
        return $this === self::UniqueUsers;
    }

    public function aggregateSql(): string
    {
        return match ($this) {
            self::TotalEvents => 'uniqExact(uuid)',
            // Two disjoint populations that are added: the persons of mapped events, and the distinct ids that have no
            // mapping (each counts as one user). A mapped id contributes its PERSON only, an unmapped one its id only, so
            // the nil UUID a LEFT JOIN yields for "no match" is never counted and a distinct id that happens to look like a
            // person uuid cannot collide with that person. `m_mapped` is the join flag (0 = no mapping).
            self::UniqueUsers => 'uniqExactIf(m_person, m_mapped = 1) + uniqExactIf(distinct_id, m_mapped = 0)',
        };
    }
}
