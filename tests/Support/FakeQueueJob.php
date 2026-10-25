<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/** A queue job double: reports a chosen attempt number and records whether and how it was released. */
final class FakeQueueJob extends Job implements JobContract
{
    public ?int $releasedWithDelay = null;

    public function __construct(private int $attemptNumber = 1)
    {
        $this->connectionName = 'redis';
        $this->queue = 'insights';
    }

    public function release($delay = 0)
    {
        parent::release($delay);
        $this->releasedWithDelay = (int) $delay;
    }

    public function attempts()
    {
        return $this->attemptNumber;
    }

    public function getJobId()
    {
        return 'fake';
    }

    public function getRawBody()
    {
        return '{}';
    }
}
