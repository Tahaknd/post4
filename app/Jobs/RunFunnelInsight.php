<?php

namespace App\Jobs;

use App\Domain\Insights\InsightTypes;

/** The funnel insight run. Everything it does is in {@see RunInsight}; this class only names the type. */
class RunFunnelInsight extends RunInsight
{
    protected function insightType(): string
    {
        return InsightTypes::FUNNEL;
    }
}
