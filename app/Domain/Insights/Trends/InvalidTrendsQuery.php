<?php

namespace App\Domain\Insights\Trends;

use App\Domain\Insights\Query\InvalidInsightQuery;

/**
 * A TrendsQuery definition that violates a rule of the trends type (series, breakdown, row cap, version, shape).
 * Same contract as {@see InvalidInsightQuery}: the message names the rule, never the offending value.
 */
class InvalidTrendsQuery extends InvalidInsightQuery {}
