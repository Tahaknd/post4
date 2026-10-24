<?php

namespace App\Actions\Projects;

use App\Domain\Projects\ProjectAudit;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a project row (its project_user rows go with it through the foreign key) and, unless told to keep
 * them, queues the removal of its ClickHouse events once the deletion is committed (see QueueEventsPurge).
 */
final class DeleteProject
{
    public function __construct(private readonly QueueEventsPurge $queueEventsPurge) {}

    public function handle(Project $project, ?User $actor = null, bool $purgeEvents = true): DeleteProjectResult
    {
        $result = new DeleteProjectResult(deleted: false, purgeRequested: $purgeEvents);

        DB::transaction(function () use ($project, $actor, $purgeEvents, &$result): void {
            $locked = Project::query()->lockForUpdate()->find($project->getKey());

            if ($locked === null) {
                return; // already gone: deleting twice is not an error
            }

            $id = (int) $locked->getKey();

            // Through the model, so the observer forgets the cached key.
            $locked->delete();

            $result = new DeleteProjectResult(deleted: true, purgeRequested: $purgeEvents);

            ProjectAudit::record('delete', $actor, $id, ['purge_events' => $purgeEvents]);

            if ($purgeEvents) {
                DB::afterCommit(function () use ($id, $result): void {
                    $result->purgeQueued = $this->queueEventsPurge->handle($id);
                });
            }
        });

        return $result;
    }
}
