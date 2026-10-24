<?php

namespace App\Actions\Projects;

use App\Domain\Projects\ProjectAudit;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Takes a user's membership away. The user's access ends with the row (an administrator keeps reaching every
 * project regardless).
 */
final class RemoveProjectMember
{
    /**
     * @return bool true when a membership was removed, false when there was none
     */
    public function handle(Project $project, User $member, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($project, $member, $actor): bool {
            $removed = $project->users()->detach($member->getKey()) > 0;

            if ($removed) {
                ProjectAudit::record('remove_member', $actor, $project->getKey(), ['member_id' => $member->getKey()]);
            }

            return $removed;
        });
    }
}
