<?php

namespace App\Jobs;

use App\Domain\Insights\InsightTypes;

/** The retention insight run. Everything it does is in {@see RunInsight}; this class only names the type. */
class RunRetentionInsight extends RunInsight
{
    protected function insightType(): string
    {
        return InsightTypes::RETENTION;
    }
}
