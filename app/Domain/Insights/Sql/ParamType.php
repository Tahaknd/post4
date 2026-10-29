<?php

namespace App\Domain\Insights\Sql;

/**
 * The ClickHouse parameter types a query builder may bind. A closed enum, not a free string: the type text
 * ends up inside SQL (`{name:Type}`), so it must never come from a caller.
 *
 * `DateTime64(3)` is written WITHOUT a zone on purpose (a zone needs a quoted literal, which the reader lint
 * rejects): the value is parsed in the session zone, which the ro_insights profile pins to UTC, and the
 * client sends UTC.
 */
enum ParamType: string
{
    case Text = 'String';
    case SmallCount = 'UInt8';
    case Count64 = 'UInt64';
    case TextList = 'Array(String)';
    case Instant = 'DateTime64(3)';
}
