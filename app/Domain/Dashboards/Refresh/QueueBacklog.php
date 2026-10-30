<?php

namespace App\Domain\Dashboards\Refresh;

/** How many insight jobs (both insight queues) are waiting right now. The scheduled refresh does not add to a backlog. @throws \Throwable when the queue cannot be asked */
interface QueueBacklog
{
    public function size(): int;
}
