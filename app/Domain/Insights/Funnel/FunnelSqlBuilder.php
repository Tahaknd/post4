<?php

namespace App\Domain\Insights\Funnel;

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
 * FunnelQuery to SQL for ProjectScopedReader. Like every builder it is assembled ONLY from class constants, enum
 * fragments and the `{name:Type}` placeholders {@see Params} returns: no value of the definition is ever concatenated
 * (the reader lint rejects string literals and `$`, and the parameter names do not depend on values). The text depends
 * on the SHAPE of the definition only: the number of steps, the operator of each filter, and the order.
 *
 * Output: one row per `level` (the deepest step a person reached, 1..steps; people who never did step 1 in the range
 * are not rows) with `people`, the number of people that ended there. {@see FunnelResult} turns it into
 * "reached at least step k" counts.
 *
 * HOW A PERSON IS EVALUATED (four nested selects, innermost first)
 *  1. Over {@see PersonSource::SQL}: the events of the window `[from, to + conversion window)`, with the person key
 *     ({@see PersonSource::KEY}, grouped together with `m_mapped`), the time in milliseconds and `hit`: the list of steps
 *     the event belongs to (its name matches and every filter of the step holds; usually zero or one step). For `ordered`
 *     only events that are some step's event are read; for `strict_order` every event of the window is, because any of
 *     them can interrupt a chain.
 *  2. One row per (event, step it belongs to): `arrayJoin(hit)`. An event that belongs to no step is dropped, or, for
 *     `strict_order`, kept as step 0 so ClickHouse sees the interruption.
 *  3. `windowFunnel` per person, over a SYNTHETIC time `ms * 16 + 15 - step`. ClickHouse's own tie handling lets ONE event
 *     match two steps (a funnel with page view as steps 1 and 2 would count a single page view as both) and lets two
 *     different events at the same instant chain. The synthetic time puts the rows of one event in DESCENDING step order
 *     and keeps events of different instants 16 ticks per millisecond apart, so an event can serve one step of one chain
 *     only and equal instants never chain, while the conversion window (given in the same ticks, `window` = ms * 16) is
 *     exact to the millisecond. The first condition is `step = 1 AND ms < to`: only a step 1 event INSIDE the range starts
 *     a conversion, while the later steps may happen up to the end of the window past the range.
 *  4. The people per level.
 *
 * MEMORY. windowFunnel keeps every relevant event of a person (about 16 bytes each) until the person is complete, and the
 * join with the id mapping holds the whole mapping of the project ({@see PersonSource}); both count against the 400 MB
 * cap of the read-only profile and a funnel over too much data fails cleanly as ClickHouseErrorKind::MemoryLimit ("query
 * too large"), never with a wrong number. `strict_order` reads all events, so it reaches the cap first.
 *
 * KNOWN BEHAVIOURS of the ClickHouse function that the tests pin: the best chain over several step 1 events is found by
 * a single greedy pass that always restarts from the latest step 1 event, which gives the longest chain; in `strict_order`
 * mode the first interruption after the first step 1 event ends the evaluation (see {@see FunnelOrder::StrictOrder}).
 */
final class FunnelSqlBuilder implements InsightSqlBuilder
{
    /** Ticks per millisecond of the synthetic time; more than the number of steps (+1 for the interruption step 0). */
    private const TICKS = 16;

    public function build(InsightQuery $query): BuiltQuery
    {
        if (! $query instanceof FunnelQuery) {
            throw new InvalidArgumentException('FunnelSqlBuilder builds funnel queries only, got '.$query::class);
        }

        $p = new Params;
        $from = $p->bind('from', ParamType::Instant, $query->range->from);
        $to = $p->bind('to', ParamType::Instant, $query->range->to);
        $until = $p->bind('until', ParamType::Instant, $query->readsUntil());
        // Seconds to the ticks of the synthetic time: milliseconds, times TICKS.
        $window = $p->bind('window', ParamType::Count64, $query->window->seconds * 1000 * self::TICKS);
        $levels = $p->bind('levels', ParamType::SmallCount, count($query->steps));

        $mode = $query->order->windowFunnelMode();
        $funnel = $mode === null ? $window : $window.', '.$p->bind('mode', ParamType::Text, $mode);

        $hits = [];
        $conditions = [];
        foreach ($query->steps as $i => $step) {
            $number = $i + 1;
            $hit = 'event = '.$p->bind("s{$i}_event", ParamType::Text, $step->event);
            foreach ($step->filters as $j => $filter) {
                $hit .= ' AND '.PropertyFilterSql::condition($p, $filter, "s{$i}_f{$j}");
            }
            $hits[] = "if({$hit}, {$number}, 0)";
            $conditions[] = $number === 1
                ? 'step = 1 AND ms < toUInt64(toUnixTimestamp64Milli('.$to.'))'
                : "step = {$number}";
        }

        $where = "timestamp >= {$from} AND timestamp < {$until}";
        if (! $query->order->readsEveryEvent()) {
            $where .= ' AND event IN '.$p->bind('names', ParamType::TextList, $query->eventNames());
        }
        $steps = $query->order->readsEveryEvent() ? 'if(empty(hit), [0], hit)' : 'hit';
        $key = PersonSource::KEY;
        $source = PersonSource::SQL;
        $ticks = self::TICKS;
        $last = self::TICKS - 1;

        $sql = 'SELECT level, count() AS people FROM ('
            ."SELECT windowFunnel({$funnel})(toUInt64(ms * {$ticks} + {$last} - step), ".implode(', ', $conditions).') AS level '
            .'FROM (SELECT m_mapped, pk, ms, arrayJoin('.$steps.') AS step '
            ."FROM (SELECT m_mapped, {$key} AS pk, toUInt64(toUnixTimestamp64Milli(timestamp)) AS ms, "
            .'arrayFilter(x -> x > 0, ['.implode(', ', $hits).']) AS hit '
            ."FROM {$source} WHERE {$where})) "
            .'GROUP BY m_mapped, pk) '
            ."WHERE level > 0 GROUP BY level ORDER BY level ASC LIMIT {$levels}";

        return new BuiltQuery($sql, $p->all());
    }

    public function result(InsightQuery $query, array $rows): InsightResult
    {
        if (! $query instanceof FunnelQuery) {
            throw new InvalidArgumentException('FunnelSqlBuilder reads the rows of funnel queries only, got '.$query::class);
        }

        return FunnelResult::fromRows($query, $rows);
    }
}
