<?php

namespace App\Domain\Insights\Trends;

use App\Domain\Insights\BuiltQuery;
use App\Domain\Insights\InsightQuery;
use App\Domain\Insights\InsightResult;
use App\Domain\Insights\InsightSqlBuilder;
use App\Domain\Insights\Sql\Params;
use App\Domain\Insights\Sql\ParamType;
use App\Domain\Insights\Sql\PersonSource;
use App\Domain\Insights\Sql\PropertyFilterSql;
use InvalidArgumentException;

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
 * mapping, so the reader does not even add its CTE). `Metric::UniqueUsers` reads {@see PersonSource::SQL} instead (what a
 * person is, the cost of the join and the query-time semantics are documented there). The unique users query measured on
 * 25.8 with the real read-only profile (400 MB cap), 7 day window, daily buckets, peak memory / time, plain distinct id
 * count -> person count:
 *   200k events, 20k ids, 15k mapped      5 MiB / 11 ms   ->  12 MiB /  16 ms
 *   1M events, 150k ids, 110k mapped     58 MiB / 29 ms   ->  67 MiB /  72 ms
 *   2.1M events, 600k ids, 450k mapped  259 MiB / 87 ms   -> 221 MiB / 191 ms   (the old count is near the cap already)
 *   300k events, 1M mapped ids                           ->  over the cap: 241 MEMORY_LIMIT_EXCEEDED (the view alone: 138 MiB)
 * An event counts for its person when its id is mapped and for the distinct id itself otherwise.
 *
 * Week and month buckets are DATES in ClickHouse (toStartOfInterval returns Date), so the bucket is routed
 * through toDateTime(..., zone) to get the local midnight instant instead of UTC midnight of that date.
 */
final class TrendsSqlBuilder implements InsightSqlBuilder
{
    public function build(InsightQuery $query): BuiltQuery
    {
        if (! $query instanceof TrendsQuery) {
            throw new InvalidArgumentException('TrendsSqlBuilder builds trends queries only, got '.$query::class);
        }

        $p = new Params;
        // Not named `events`: the reader lint bans that word, placeholders included.
        $names = $p->bind('names', ParamType::TextList, $query->events);
        $from = $p->bind('from', ParamType::Instant, $query->range->from);
        $to = $p->bind('to', ParamType::Instant, $query->range->to);
        $tz = $p->bind('tz', ParamType::Text, $query->timezone());

        $where = "event IN {$names} AND timestamp >= {$from} AND timestamp < {$to}";
        foreach ($query->filters as $i => $filter) {
            $where .= ' AND '.PropertyFilterSql::condition($p, $filter, "f{$i}");
        }

        $agg = $query->metric->aggregateSql();
        $source = $query->metric->needsPersons() ? PersonSource::SQL : 'project_events';
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

    public function result(InsightQuery $query, array $rows): InsightResult
    {
        if (! $query instanceof TrendsQuery) {
            throw new InvalidArgumentException('TrendsSqlBuilder reads the rows of trends queries only, got '.$query::class);
        }

        return TrendsResult::fromRows($query, $rows);
    }
}
