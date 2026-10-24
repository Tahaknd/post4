<?php

namespace App\Actions\Projects;

use App\Domain\Projects\ProjectAudit;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Makes a user a member of a project (a project_user row). Adding an existing member changes nothing and
 * reports it.
 */
final class AddProjectMember
{
    /**
     * @return bool true when the membership was created, false when the user already was a member
     */
    public function handle(Project $project, User $member, ProjectRole $role = ProjectRole::Member, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($project, $member, $role, $actor): bool {
            $changes = $project->users()->syncWithoutDetaching([$member->getKey() => ['role' => $role->value]]);
            $created = $changes['attached'] !== [];

            if ($created) {
                ProjectAudit::record('add_member', $actor, $project->getKey(), ['member_id' => $member->getKey(), 'role' => $role->value]);
            }

            return $created;
        });
    }
}
