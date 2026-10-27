<?php

namespace App\Domain\Insights\Trends;

use InvalidArgumentException;

/**
 * A TrendsQuery definition that violates a rule (limits, allow lists, shape). The message names the rule and
 * the limit, never echoes the offending value, so it is safe to show to the user who typed it.
 */
class InvalidTrendsQuery extends InvalidArgumentException {}
