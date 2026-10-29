<?php

namespace App\Domain\Insights\Funnel;

use App\Domain\Insights\Query\InvalidInsightQuery;

/**
 * A FunnelQuery definition that violates a rule of the funnel type (steps, window, order, version, shape). Same
 * contract as {@see InvalidInsightQuery}: the message names the rule, never the offending value.
 */
class InvalidFunnelQuery extends InvalidInsightQuery {}
