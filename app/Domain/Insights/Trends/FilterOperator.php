<?php

namespace App\Domain\Insights\Trends;

/**
 * Property filter operators. Values are compared as TEXT through JSONExtractString, which on ClickHouse 25.8
 * returns the text of a string, and ALSO of a number or a boolean (`{"n":5}` gives `5`, `{"b":true}` gives
 * `true`, `1.50` gives `1.5`: the number is normalised), so numeric and boolean properties match string filters
 * (pinned by TrendsIntegrationTest). A JSON null, a missing key, and (for a nested object or array) their raw JSON
 * text are the other cases: null and missing give the EMPTY string, so
 *   - `Equals ''` matches events where the property is empty, null OR absent;
 *   - `NotEquals 'x'` matches events where the property is absent or null as well (they differ from 'x');
 *   - `NotEquals ''` matches only events that have a non-empty value.
 */
enum FilterOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    /** Case insensitive substring (a plain search, no LIKE pattern: % and _ are ordinary characters). */
    case Contains = 'contains';
    /** The key exists and is not JSON null (no value). */
    case IsSet = 'is_set';

    public function needsValue(): bool
    {
        return $this !== self::IsSet;
    }
}
