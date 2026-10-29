<?php

namespace App\Jobs;

/**
 * Removes every event of a deleted project from posthog.events. All of the behaviour (the asynchronous lightweight
 * delete, waiting by releasing, the safety checks, the unique lock, the failure reporting) lives in
 * {@see PurgeProjectRows}, which also documents the measurements behind it.
 */
class PurgeProjectEvents extends PurgeProjectRows
{
    protected function table(): string
    {
        return 'events';
    }
}
