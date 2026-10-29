<?php

namespace App\Domain\Insights\Sql;

use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\PropertyFilter;

/**
 * One {@see PropertyFilter} as a SQL condition on the `properties` JSON of an event. Key and value only ever travel as
 * bound parameters; the text is made of enum-chosen fragments and the placeholders {@see Params} returns. The caller
 * gives a PREFIX that makes the parameter names unique inside its query (`f0`, `s2_f1`): the names are `{prefix}_key`,
 * `{prefix}_value` and `{prefix}_null`, never derived from values.
 */
final class PropertyFilterSql
{
    private function __construct() {}

    public static function condition(Params $p, PropertyFilter $filter, string $prefix): string
    {
        $key = $p->bind("{$prefix}_key", ParamType::Text, $filter->key);
        $property = "JSONExtractString(properties, {$key})";

        return match ($filter->operator) {
            FilterOperator::Equals => "{$property} = ".$p->bind("{$prefix}_value", ParamType::Text, (string) $filter->value),
            FilterOperator::NotEquals => "{$property} != ".$p->bind("{$prefix}_value", ParamType::Text, (string) $filter->value),
            FilterOperator::Contains => "positionCaseInsensitiveUTF8({$property}, ".$p->bind("{$prefix}_value", ParamType::Text, (string) $filter->value).') > 0',
            // The raw JSON text of a JSON null is `null`: present and not null. (The word is a bound constant.)
            FilterOperator::IsSet => "(JSONHas(properties, {$key}) AND JSONExtractRaw(properties, {$key}) != ".$p->bind("{$prefix}_null", ParamType::Text, 'null').')',
        };
    }
}
