<?php

namespace App\Domain\Insights\Events;

use App\Domain\Insights\BuiltQuery;
use App\Domain\Insights\Sql\Params;
use App\Domain\Insights\Sql\ParamType;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsSqlBuilder;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The SQL of the event picker's name list, for ProjectScopedReader: the most frequent event names of the last
 * {@see self::WINDOW_DAYS} UTC days with their counts. Like {@see TrendsSqlBuilder} the
 * text is assembled ONLY from class constants and the `{name:Type}` placeholders {@see Params} returns: the window
 * and the optional search text are bound parameters, never concatenated, there are no string literals and no `$`
 * (the reader lint rejects both). So the SQL text depends only on whether a search text is present, whatever the
 * text is (a test proves it with hostile values).
 *
 * The search is a case-insensitive SUBSTRING match through positionCaseInsensitiveUTF8 with a typed parameter, not
 * LIKE: `%`, `_`, quotes and backslashes in what the user typed are plain characters, nothing needs escaping.
 *
 * The window is a predicate on `timestamp` (the partition key), so partitions outside it are pruned. Names longer
 * than a trend definition accepts (TrendsQuery::MAX_EVENT_LENGTH) are left out: the picker could never use them.
 * The query asks for one row more than {@see self::MAX_NAMES}; a result with that extra row is `truncated`.
 */
final class EventNamesQuery
{
    /** Names the picker is shown (and the API returns). */
    public const MAX_NAMES = 100;

    /** Longest search text, in characters. */
    public const MAX_SEARCH_LENGTH = 100;

    /** UTC calendar days of the window, today included. */
    public const WINDOW_DAYS = 30;

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} start (inclusive) and end (exclusive) of the window, UTC */
    public static function window(DateTimeInterface $now): array
    {
        $today = DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);

        return [$today->modify('-'.(self::WINDOW_DAYS - 1).' days'), $today->modify('+1 day')];
    }

    /** @param  ?string  $search  already normalised (trimmed, lower case); null or '' means no search */
    public function build(DateTimeInterface $now, ?string $search = null): BuiltQuery
    {
        [$start, $end] = self::window($now);

        $p = new Params;
        $from = $p->bind('from', ParamType::Instant, $start);
        $to = $p->bind('to', ParamType::Instant, $end);

        $where = "timestamp >= {$from} AND timestamp < {$to} AND lengthUTF8(event) <= ".TrendsQuery::MAX_EVENT_LENGTH;
        if ($search !== null && $search !== '') {
            $where .= ' AND positionCaseInsensitiveUTF8(event, '.$p->bind('q', ParamType::Text, $search).') > 0';
        }

        $limit = self::MAX_NAMES + 1;

        return new BuiltQuery(
            "SELECT event AS name, count() AS total FROM project_events WHERE {$where} GROUP BY name ORDER BY total DESC, name ASC LIMIT {$limit}",
            $p->all(),
        );
    }
}
