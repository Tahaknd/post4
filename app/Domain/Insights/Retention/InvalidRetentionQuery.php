<?php

namespace App\Domain\Insights\Retention;

use App\Domain\Insights\Query\InvalidInsightQuery;

/**
 * A RetentionQuery definition that violates a rule of the retention type (period, number of periods, type, zone, version,
 * shape). Same contract as {@see InvalidInsightQuery}: the message names the rule, never the offending value.
 */
class InvalidRetentionQuery extends InvalidInsightQuery {}
