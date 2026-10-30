<?php

namespace App\Infrastructure\Queue;

use App\Domain\Dashboards\Refresh\QueueBacklog;
use App\Jobs\RunInsight;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

/** The number of jobs waiting (ready, delayed and reserved) on the interactive and the refresh insight queues of the default connection. */
final class InsightQueueBacklog implements QueueBacklog
{
    public function __construct(private readonly QueueFactory $queues) {}

    public function size(): int
    {
        $connection = $this->queues->connection();

        return $connection->size(RunInsight::QUEUE) + $connection->size((string) config('dashboards.refresh.queue'));
    }
}
