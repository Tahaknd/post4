<?php

namespace App\Actions\Projects;

use App\Jobs\PurgeProjectEvents;
use Illuminate\Support\Facades\Log;

/**
 * Queues the removal of a deleted project's ClickHouse events: the purge now and a second pass a few minutes
 * later. Capture rejects an unknown key (401), but an event accepted a moment before the deletion can still sit
 * in Kafka and land in ClickHouse after the first purge; the second pass removes those stragglers. Events that
 * stay in flight longer than that (a consumer that was stopped) are caught by the daily `events:sweep-orphans`.
 *
 * Shared by DeleteProject and `project:purge-events`. A failure to queue (Redis down) is logged ONCE here and
 * reported to the caller as false; it never throws.
 */
final class QueueEventsPurge
{
    /** Seconds between the first purge and the second pass. */
    public const int SECOND_PASS_DELAY_SECONDS = 600;

    public function handle(int $projectId): bool
    {
        try {
            PurgeProjectEvents::dispatch($projectId, pass: 1);
            PurgeProjectEvents::dispatch($projectId, pass: 2)->delay(self::SECOND_PASS_DELAY_SECONDS);

            return true;
        } catch (\Throwable $e) {
            Log::error('project purge could not be queued; its events remain in ClickHouse until events:sweep-orphans or project:purge-events removes them', [
                'project_id' => $projectId,
                'exception' => $e::class,
            ]);

            return false;
        }
    }
}
