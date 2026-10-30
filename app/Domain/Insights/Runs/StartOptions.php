<?php

namespace App\Domain\Insights\Runs;

use App\Jobs\RunInsight;

/**
 * What differs between an interactive request and the scheduled refresh when they start a run. The defaults are the
 * interactive ones.
 */
final readonly class StartOptions
{
    public function __construct(
        /** The result also goes to the dashboard result cache (saved-insight runs and the refresh), with its own longer TTL. */
        public bool $forDashboard = false,
        /** The queue of the job; the refresh uses a lower priority one. */
        public string $queue = RunInsight::QUEUE,
        /** Seconds the job waits before it may start (jitter of the refresh). */
        public int $delaySeconds = 0,
    ) {}
}
