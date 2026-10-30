<?php

namespace App\Domain\Dashboards\Refresh;

/** Can insight queries run right now? The scheduled refresh asks before it queues anything (never throws). */
interface ClickHouseHealth
{
    public function isHealthy(): bool;
}
