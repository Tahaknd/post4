<?php

namespace App\Domain\Insights\Retention;

use App\Domain\Insights\BuiltQuery;
use App\Domain\Insights\InsightQuery;
use App\Domain\Insights\InsightResult;
use App\Domain\Insights\InsightSqlBuilder;
use App\Domain\Insights\Query\EventCondition;
use App\Domain\Insights\Sql\Params;
use App\Domain\Insights\Sql\ParamType;
use App\Domain\Insights\Sql\PersonSource;
use App\Domain\Insights\Sql\PropertyFilterSql;
use InvalidArgumentException;

/**
 * RetentionQuery to SQL for ProjectScopedReader. Like every builder it is assembled ONLY from class constants, enum
 * fragments and the `{name:Type}` placeholders {@see Params} returns: no value of the definition is ever concatenated
 * (the reader lint rejects string literals and `$`, and the parameter names do not depend on values). The text depends on
 * the SHAPE of the definition only: the period, the retention type, and the operator of each filter.
 *
 * Output: one row per cohort that has people, `cohort` (0-based index of the cohort period, counted from the first
 * period of {@see RetentionQuery::$starts}), `size` (people in the cohort) and `retained`, an array of `periods` counts
 * (people who did the return event 1, 2, .. periods periods after the cohort period). {@see RetentionResult} puts the
 * period starts, the nulls of periods that have not started and the rates around it.
 *
 * HOW A PERSON IS EVALUATED (three nested selects, innermost first)
 *  1. Over {@see PersonSource::SQL}: the events of the window `[first cohort period, end of the follow-up)` that are the
 *     target or the return event, with the person key ({@see PersonSource::KEY}, grouped together with `m_mapped`), the
 *     period index `i` (the ClickHouse period counter of the event minus that of the first cohort period, so it needs no
 *     unit string and follows the local calendar and daylight saving time of the zone) and the two flags `is_target` and
 *     `is_return` (name equal and every filter of that role holds; one event can be both). Events that are neither are
 *     dropped.
 *  2. One row per person with the DISTINCT period indexes of their target events (`cs`) and of their return events (`rs`):
 *     `recurring` keeps every cohort period they were active in, `first_time` only the first. This is the one place whose size
 *     grows with the number of people: the GROUP BY spills to disk past the profile's threshold (see
 *     docker/clickhouse/users.d/20-readonly-user.xml) and fails cleanly as MEMORY_LIMIT_EXCEEDED, kind "query too large", beyond
 *     that. People with no target event inside a cohort period are dropped here.
 *     The state is a plain `groupArrayIf` of the period indexes (8 bytes per event of the person) made distinct afterwards, and
 *     when target and return are the same event the cohort periods are derived from the return periods instead of collected a
 *     second time. The alternatives were measured on 6M events / 500k people / 12 weekly cohorts through the read-only profile:
 *     `groupUniqArrayIf` (a hash set per person) peaked at 282 MiB (weekly) and 354 MiB (daily, 30 periods: next to the 381 MiB
 *     cap), `groupBitmapStateIf` at 228 and 293 MiB, `groupArrayIf` + `arrayDistinct` at 222 and 256 MiB and was the fastest, with
 *     byte for byte equal answers; collecting the same event once took the weekly table to 193 MiB and 0.65 s. The cost of the
 *     array is that one person with millions of events holds all of them (8 bytes
 *     each) until the end of the aggregation; the cap turns that into the clean memory error.
 *  3. Each (person, cohort period) pair becomes a row with the flags "returned in period k" for k = 1..periods, and the pairs
 *     are summed per cohort with `sumForEach`: the result has one row per cohort, never one per person.
 */
final class RetentionSqlBuilder implements InsightSqlBuilder
{
    public function build(InsightQuery $query): BuiltQuery
    {
        if (! $query instanceof RetentionQuery) {
            throw new InvalidArgumentException('RetentionSqlBuilder builds retention queries only, got '.$query::class);
        }

        $p = new Params;
        $from = $p->bind('from', ParamType::Instant, $query->readsFrom());
        $until = $p->bind('until', ParamType::Instant, $query->readsUntil());
        $tz = $p->bind('tz', ParamType::Text, $query->timezone);
        $cohorts = $p->bind('cohorts', ParamType::Count64, $query->cohorts);
        $periods = $p->bind('periods', ParamType::SmallCount, $query->periods);
        $names = $p->bind('names', ParamType::TextList, $query->eventNames());

        $isTarget = $this->condition($p, $query->target, 'target');
        $isReturn = $this->condition($p, $query->returning, 'return');

        $number = $query->period->numberFunction();
        $key = PersonSource::KEY;
        $source = PersonSource::SQL;

        // The return periods of a person (`rs`) and their cohort periods (`cs`): the periods of the table in which they did the
        // target event. When both roles are the same event (the usual "came back" table) the cohort periods are derived from `rs`
        // instead of being collected a second time: the state of a person is the largest memory consumer of the query.
        $maxCohort = "toInt64({$cohorts})";
        $returned = 'arrayDistinct(groupArrayIf(i, is_return)) AS rs';
        if ($query->target->canonical() === $query->returning->canonical()) {
            $cohortPeriods = $query->type === RetentionType::Recurring
                ? "arrayFilter(x -> x < {$maxCohort}, rs) AS cs"
                : "if(empty(rs), [], arrayFilter(x -> x < {$maxCohort}, [arrayMin(rs)])) AS cs";
            $people = "{$returned}, {$cohortPeriods}";
        } else {
            // Restricted to the periods of the table: a target event in the follow-up tail after the last cohort starts no cohort.
            $cohortPeriods = $query->type === RetentionType::Recurring
                ? "arrayDistinct(groupArrayIf(i, is_target AND i < {$maxCohort})) AS cs"
                : "if(countIf(is_target) > 0, arrayFilter(x -> x < {$maxCohort}, [minIf(i, is_target)]), []) AS cs";
            $people = "{$cohortPeriods}, {$returned}";
        }

        $sql = 'SELECT cohort, count() AS size, sumForEach(returned) AS retained FROM ('
            .'SELECT arrayJoin(cs) AS cohort, '
            ."arrayMap(k -> has(rs, cohort + k), range(1, {$periods} + 1)) AS returned FROM ("
            ."SELECT {$people} FROM ("
            .'SELECT m_mapped, pk, i, is_target, is_return FROM ('
            ."SELECT m_mapped, {$key} AS pk, "
            ."toInt64({$number}(timestamp, {$tz})) - toInt64({$number}({$from}, {$tz})) AS i, "
            ."({$isTarget}) AS is_target, ({$isReturn}) AS is_return "
            ."FROM {$source} WHERE timestamp >= {$from} AND timestamp < {$until} AND event IN {$names}"
            .') WHERE is_target OR is_return'
            .') GROUP BY m_mapped, pk HAVING length(cs) > 0'
            .')) GROUP BY cohort ORDER BY cohort ASC '
            ."LIMIT {$cohorts}";

        return new BuiltQuery($sql, $p->all());
    }

    public function result(InsightQuery $query, array $rows): InsightResult
    {
        if (! $query instanceof RetentionQuery) {
            throw new InvalidArgumentException('RetentionSqlBuilder reads the rows of retention queries only, got '.$query::class);
        }

        // `now()` (the application clock, which tests fix) decides which periods have started.
        return RetentionResult::fromRows($query, $rows, now());
    }

    /** The SQL condition "this event is the event of the role": its name and every filter. */
    private function condition(Params $p, EventCondition $condition, string $prefix): string
    {
        $sql = 'event = '.$p->bind("{$prefix}_event", ParamType::Text, $condition->event);
        foreach ($condition->filters as $j => $filter) {
            $sql .= ' AND '.PropertyFilterSql::condition($p, $filter, "{$prefix}_f{$j}");
        }

        return $sql;
    }
}
