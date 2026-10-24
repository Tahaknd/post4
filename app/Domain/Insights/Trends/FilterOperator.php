<?php

namespace App\Domain\Insights\Trends;

/** Property filter operators. Property values are strings (JSONExtractString), so are the comparisons. */
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
