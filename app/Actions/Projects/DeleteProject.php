<?php

namespace App\Actions\Projects;

use App\Domain\Projects\ProjectAudit;
use App\Jobs\PurgeProjectEvents;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes a project row (its project_user rows go with it through the foreign key) and, unless told to keep
 * them, queues the removal of its ClickHouse events once the deletion is committed.
 *
 * The purge runs twice. Capture rejects an unknown key (401), but an event accepted a moment before the
 * deletion can still sit in Kafka and land in ClickHouse after the first purge; a second pass a few minutes
 * later removes those stragglers. Events that stay in flight longer than that (a consumer that was stopped)
 * are not covered: see PurgeProjectEvents.
 */
final class DeleteProject
{
    /** Seconds between the first purge and the second pass. */
    public const int SECOND_PASS_DELAY_SECONDS = 600;

    public function handle(Project $project, ?User $actor = null, bool $purgeEvents = true): void
    {
        DB::transaction(function () use ($project, $actor, $purgeEvents): void {
            $locked = Project::query()->lockForUpdate()->find($project->getKey());

            if ($locked === null) {
                return; // already gone: deleting twice is not an error
            }

            $id = (int) $locked->getKey();

            // Through the model, so the observer forgets the cached key.
            $locked->delete();

            ProjectAudit::record('delete', $actor, $id, ['purge_events' => $purgeEvents]);

            if ($purgeEvents) {
                DB::afterCommit(fn () => $this->queuePurge($id));
            }
        });
    }

    private function queuePurge(int $projectId): void
    {
        try {
            PurgeProjectEvents::dispatch($projectId, pass: 1);
            PurgeProjectEvents::dispatch($projectId, pass: 2)->delay(self::SECOND_PASS_DELAY_SECONDS);
        } catch (\Throwable $e) {
            // The project is already deleted: failing the request would not bring it back. Say loudly that its
            // events are still in ClickHouse and how to remove them.
            report($e);
            Log::error('project purge could not be queued; its events remain in ClickHouse', ['project_id' => $projectId]);
        }
    }
}
