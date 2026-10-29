<?php

namespace App\Domain\Insights\Sql;

/**
 * The events relation with the PERSON of each event, the one source of "who did it" for every insight that counts
 * people (unique users of trends, funnels). Constants only: no value of any definition appears in it.
 *
 * Every column of `project_events` plus `m_mapped` (1 when the distinct id has a mapping, else 0) and `m_person` (its
 * person; the nil UUID when there is none, which is why every use is guarded by `m_mapped`). The right side is the
 * scoped, deduplicated mapping `project_person_distinct_ids` (distinct id -> person, the newest version of each id,
 * tombstones absent; the reader adds that CTE because the SQL names it), renamed with the `m_` prefix so no column of the
 * events can be ambiguous and the predicates of a query (written for plain events) apply unchanged; ClickHouse pushes them
 * below the join, so the range and the project key still prune the events scan (verified: the rows read for a one day
 * window stay a day of events plus the mapping). `ifNull` makes the flag 0 even if a session ever sets join_use_nulls=1,
 * which would otherwise make the unmatched side NULL and silently drop it from the counts.
 *
 * A person is the pair ({@see self::KEY}, `m_mapped`): the person of a mapped id, the distinct id itself otherwise (an
 * id that was never identified has no row and is one user). Grouping by BOTH columns keeps the two populations disjoint:
 * a distinct id that happens to look like a person uuid can never collide with that person, and the nil UUID of an
 * unmatched row is never a group of its own.
 *
 * The join is a hash join (the profile pins join_algorithm=hash) that holds the WHOLE mapping of the project in memory:
 * nothing narrows it, because the view aggregates before any filter could apply and a restriction to the ids of the window
 * needs a second scan of the events (measured slower and larger). Its memory therefore grows with the number of MAPPED
 * distinct ids of the project, not with the events: roughly 450 bytes per mapped id, a ceiling near 800k mapped ids per
 * project under the 400 MB cap of the read-only profile (1M mapped ids: 241 MEMORY_LIMIT_EXCEEDED, the view alone is 138
 * MiB). Beyond it a run fails cleanly as ClickHouseErrorKind::MemoryLimit ("query too large"), never with a wrong number.
 *
 * Semantics (read at query time, on purpose): the mapping is joined when the query RUNS, not when the event was captured.
 * So identifying an anonymous id retroactively merges all of its earlier events into the person, a merge moves every event
 * of the moved ids to the surviving person, and the same query can give a different answer minutes later. Cached results
 * keep the answer of their computation until they expire (config insights.cache_ttl_seconds).
 */
final class PersonSource
{
    public const SQL = '(SELECT e.*, ifNull(m.m_found, 0) AS m_mapped, m.m_person AS m_person '
        .'FROM project_events AS e LEFT JOIN '
        .'(SELECT distinct_id AS m_id, person_id AS m_person, 1 AS m_found FROM project_person_distinct_ids) AS m '
        .'ON e.distinct_id = m.m_id)';

    /** The identity of a person within one `m_mapped` value: the person uuid as text when mapped, else the distinct id. */
    public const KEY = 'if(m_mapped = 1, toString(m_person), distinct_id)';

    private function __construct() {}
}
