<?php

namespace App\Domain\Insights\Trends;

use App\Domain\Insights\BuiltQuery;
use App\Domain\Insights\Sql\Params;
use App\Domain\Insights\Sql\ParamType;

/**
 * TrendsQuery to SQL for ProjectScopedReader. The text is assembled ONLY from class constants, enum
 * fragments (Metric, Interval, BreakdownColumn: code) and the `{name:Type}` placeholders that {@see Params}
 * returns; no value of the definition is ever concatenated, there are no string literals and no `$`
 * (the reader lint rejects both), and the parameter names do not depend on values. So the SQL text depends
 * only on the SHAPE of the definition (metric, interval, breakdown kind, filter operators and their count,
 * series count never: events are one array parameter), which a test proves with hostile values.
 *
 * Output columns: `series` (event), `bucket` (UTC epoch seconds of the bucket start in the project zone),
 * `breakdown` (only with a breakdown) and `value`. It always aggregates, always has a UTC range predicate on
 * `timestamp` and always ends in LIMIT {@see TrendsQuery::MAX_ROWS} + 1.
 *
 * PERSON BASED UNIQUE USERS. `Metric::TotalEvents` reads `project_events` directly (that SQL does not mention the
 * mapping, so the reader does not even add its CTE). `Metric::UniqueUsers` reads {@see self::PERSON_SOURCE} instead: the
 * events LEFT JOINed with the scoped, deduplicated mapping `project_person_distinct_ids` (distinct id -> person, the
 * newest version of each id, tombstones absent; the reader adds that CTE because the SQL names it). The join is a hash
 * join (the profile pins join_algorithm=hash) that holds the WHOLE mapping of the project in memory: nothing narrows
 * it, because the view aggregates before any filter could apply and a restriction to the ids of the window needs a
 * second scan of the events (measured slower and larger). Its memory therefore grows with the number of MAPPED distinct
 * ids of the project, not with the events. Measured on 25.8 with the real read-only profile (400 MB cap), 7 day window,
 * daily buckets, peak memory / time, plain distinct id count -> person count:
 *   200k events, 20k ids, 15k mapped      5 MiB / 11 ms   ->  12 MiB /  16 ms
 *   1M events, 150k ids, 110k mapped     58 MiB / 29 ms   ->  67 MiB /  72 ms
 *   2.1M events, 600k ids, 450k mapped  259 MiB / 87 ms   -> 221 MiB / 191 ms   (the old count is near the cap already)
 *   300k events, 1M mapped ids                           ->  over the cap: 241 MEMORY_LIMIT_EXCEEDED (the view alone: 138 MiB)
 * so roughly 450 bytes per mapped id and a ceiling near 800k mapped ids per project; beyond it the run fails cleanly
 * as ClickHouseErrorKind::MemoryLimit ("query too large"), never with a wrong number. An event counts for its person when its id is
 * mapped and for the distinct id itself otherwise (an id that was never identified has no row and is one user).
 *
 * Semantics (read at query time, on purpose): the mapping is joined when the query RUNS, not when the event was
 * captured. So identifying an anonymous id retroactively merges all of its earlier events into the person (a window
 * that counted them as an anonymous user before now counts the person once), a merge moves every event of the moved
 * ids to the surviving person, and the same query can give a different answer minutes later. Cached results keep the
 * answer of their computation until they expire (config insights.cache_ttl_seconds).
 *
 * Week and month buckets are DATES in ClickHouse (toStartOfInterval returns Date), so the bucket is routed
 * through toDateTime(..., zone) to get the local midnight instant instead of UTC midnight of that date.
 */
final class TrendsSqlBuilder
{
    /**
     * The events relation with the person columns of the unique users metric: every column of `project_events` plus
     * `m_mapped` (1 when the distinct id has a mapping, else 0) and `m_person` (its person; the nil UUID when there is
     * none, which is why every use is guarded by `m_mapped`). The right side is renamed with the `m_` prefix so no
     * column of the events can be ambiguous and the predicates of the query (written for plain events) apply unchanged;
     * ClickHouse pushes them below the join, so the range and the project key still prune the events scan (verified: the
     * rows read for a one day window stay a day of events plus the mapping). `ifNull` makes the flag 0 even if a
     * session ever sets join_use_nulls=1, which would otherwise make the unmatched side NULL and silently drop it from
     * the counts. Constants only: no value of the definition appears here.
     */
    public const PERSON_SOURCE = '(SELECT e.*, ifNull(m.m_found, 0) AS m_mapped, m.m_person AS m_person '
        .'FROM project_events AS e LEFT JOIN '
        .'(SELECT distinct_id AS m_id, person_id AS m_person, 1 AS m_found FROM project_person_distinct_ids) AS m '
        .'ON e.distinct_id = m.m_id)';

    public function build(TrendsQuery $query): BuiltQuery
    {
        $p = new Params;
        // Not named `events`: the reader lint bans that word, placeholders included.
        $names = $p->bind('names', ParamType::TextList, $query->events);
        $from = $p->bind('from', ParamType::Instant, $query->range->from);
        $to = $p->bind('to', ParamType::Instant, $query->range->to);
        $tz = $p->bind('tz', ParamType::Text, $query->timezone());

        $where = "event IN {$names} AND timestamp >= {$from} AND timestamp < {$to}";
        foreach ($query->filters as $i => $filter) {
            $where .= ' AND '.$this->filter($p, $filter, $i);
        }

        $agg = $query->metric->aggregateSql();
        $source = $query->metric->needsPersons() ? self::PERSON_SOURCE : 'project_events';
        $personColumns = $query->metric->needsPersons() ? ', m_person, m_mapped' : '';
        $interval = $query->interval->intervalSql();
        $bucket = "toUnixTimestamp(toDateTime(toStartOfInterval(timestamp, {$interval}, {$tz}), {$tz}))";
        // One more than the cap, so a result that would be cut off is detectable (TrendsResult throws above MAX_ROWS).
        // Stays far below the profile's max_result_rows (20000), so the overflow mode never fires first.
        $limit = TrendsQuery::MAX_ROWS + 1;

        if ($query->breakdown === null) {
            $sql = "SELECT event AS series, {$bucket} AS bucket, {$agg} AS value "
                ."FROM {$source} WHERE {$where} "
                ."GROUP BY series, bucket ORDER BY bucket ASC, series ASC LIMIT {$limit}";

            return new BuiltQuery($sql, $p->all());
        }

        $value = $this->breakdownValue($p, $query->breakdown);
        $none = $p->bind('none', ParamType::Text, Breakdown::NONE_LABEL);
        $other = $p->bind('other', ParamType::Text, Breakdown::OTHER_LABEL);
        $top = $p->bind('top_n', ParamType::SmallCount, $query->breakdown->topN);
        $bd = "if(empty({$value}), {$none}, {$value})";

        // Inner query 1 (in the IN): the top N values over the WHOLE window and all selected events; ties by value.
        // Inner query 2 (the FROM): the rows with their breakdown value. Values outside the top N become `other`
        // BEFORE aggregating, so a unique count of "other" is the union of its users, not a sum of counts.
        $sql = "SELECT series, {$bucket} AS bucket, "
            ."if(bd IN (SELECT bd FROM (SELECT {$bd} AS bd, {$agg} AS c FROM {$source} WHERE {$where} "
            ."GROUP BY bd ORDER BY c DESC, bd ASC LIMIT {$top})), bd, {$other}) AS breakdown, "
            ."{$agg} AS value "
            ."FROM (SELECT event AS series, timestamp, uuid, distinct_id{$personColumns}, {$bd} AS bd FROM {$source} WHERE {$where}) "
            ."GROUP BY series, bucket, breakdown ORDER BY bucket ASC, series ASC, breakdown ASC LIMIT {$limit}";

        return new BuiltQuery($sql, $p->all());
    }

    /** SQL of the raw breakdown value (column: enum fragment; property: key parameter). */
    private function breakdownValue(Params $p, Breakdown $breakdown): string
    {
        if ($breakdown->column !== null) {
            return $breakdown->column->valueSql();
        }

        return 'JSONExtractString(properties, '.$p->bind('bd_key', ParamType::Text, (string) $breakdown->propertyKey).')';
    }

    private function filter(Params $p, PropertyFilter $filter, int $i): string
    {
        $key = $p->bind("f{$i}_key", ParamType::Text, $filter->key);
        $property = "JSONExtractString(properties, {$key})";

        return match ($filter->operator) {
            FilterOperator::Equals => "{$property} = ".$p->bind("f{$i}_value", ParamType::Text, (string) $filter->value),
            FilterOperator::NotEquals => "{$property} != ".$p->bind("f{$i}_value", ParamType::Text, (string) $filter->value),
            FilterOperator::Contains => "positionCaseInsensitiveUTF8({$property}, ".$p->bind("f{$i}_value", ParamType::Text, (string) $filter->value).') > 0',
            // The raw JSON text of a JSON null is `null`: present and not null. (The word is a bound constant.)
            FilterOperator::IsSet => "(JSONHas(properties, {$key}) AND JSONExtractRaw(properties, {$key}) != ".$p->bind("f{$i}_null", ParamType::Text, 'null').')',
        };
    }
}
