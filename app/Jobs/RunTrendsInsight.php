<?php

namespace App\Jobs;

use App\Domain\Insights\InsightTypes;

/**
 * The trends insight run. Everything it does is in {@see RunInsight}; this class only names the type. Its name, its
 * properties and therefore its queue payload are exactly what they were before the job became generic, so a
 * RunTrendsInsight that is still waiting in the Redis queue when a deployment lands is read and run by the new code
 * (pinned by a payload captured before the change, tests/Feature/RunInsightTest.php), and a worker still running the old
 * code can run what the new web code queues.
 */
class RunTrendsInsight extends RunInsight
{
    protected function insightType(): string
    {
        return InsightTypes::TRENDS;
    }
}
