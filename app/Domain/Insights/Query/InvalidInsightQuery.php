<?php

namespace App\Domain\Insights\Query;

use InvalidArgumentException;

/**
 * An insight definition (of any type) that violates a rule: limits, allow lists, shape. The message names the rule
 * and the limit, never echoes the offending value, so it is safe to show to the user who typed it. The shared query
 * vocabulary of this namespace (date range, property filter, event name) throws it directly; every insight type
 * has a subclass for its own rules (InvalidTrendsQuery, InvalidFunnelQuery), so a caller that only cares about "this
 * definition is not valid" (the request classes, the run job) catches this class.
 */
class InvalidInsightQuery extends InvalidArgumentException {}
