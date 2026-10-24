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
 * `timestamp` and always ends in LIMIT {@see TrendsQuery::MAX_ROWS}.
 *
 * Week and month buckets are DATES in ClickHouse (toStartOfInterval returns Date), so the bucket is routed
 * through toDateTime(..., zone) to get the local midnight instant instead of UTC midnight of that date.
 */
final class TrendsSqlBuilder
{
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
        $interval = $query->interval->intervalSql();
        $bucket = "toUnixTimestamp(toDateTime(toStartOfInterval(timestamp, {$interval}, {$tz}), {$tz}))";
        $limit = TrendsQuery::MAX_ROWS;

        if ($query->breakdown === null) {
            $sql = "SELECT event AS series, {$bucket} AS bucket, {$agg} AS value "
                ."FROM project_events WHERE {$where} "
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
            ."if(bd IN (SELECT bd FROM (SELECT {$bd} AS bd, {$agg} AS c FROM project_events WHERE {$where} "
            ."GROUP BY bd ORDER BY c DESC, bd ASC LIMIT {$top})), bd, {$other}) AS breakdown, "
            ."{$agg} AS value "
            ."FROM (SELECT event AS series, timestamp, uuid, distinct_id, {$bd} AS bd FROM project_events WHERE {$where}) "
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
