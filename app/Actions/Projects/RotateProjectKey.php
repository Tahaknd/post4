<?php

namespace App\Actions\Projects;

use App\Domain\Projects\ProjectAudit;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a project's API key. The old key stops working at once: ProjectObserver forgets both keys from the
 * resolver cache after the outermost commit (the model must be saved, which is why this re-reads and saves the
 * row instead of running a query-builder update).
 */
final class RotateProjectKey
{
    /**
     * @throws ModelNotFoundException when the project was deleted in the meantime
     */
    public function handle(Project $project, ?User $actor = null): Project
    {
        return DB::transaction(function () use ($project, $actor): Project {
            // Locked and re-read: two rotations (or a rotation and a deletion) take turns, and the observer
            // sees the key that is really stored as the "old" one.
            $locked = Project::query()->lockForUpdate()->findOrFail($project->getKey());

            $locked->api_key = Project::generateApiKey();
            $locked->save();

            ProjectAudit::record('rotate_key', $actor, $locked->getKey());

            return $locked;
        });
    }
}
