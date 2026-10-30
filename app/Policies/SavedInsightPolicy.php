<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\SavedInsight;
use App\Models\User;

/**
 * Who may do what with saved insights. One project role exists (member), so the rule is the one of the project itself: an
 * administrator, or a member of the project the insight belongs to, may list, read, create, edit, run and delete. Splitting
 * "may read" from "may write" later (a viewer role) is a change in this class only.
 *
 * Route model binding already limits `{project}` and `{insight}` to what the user can open (a foreign or missing one is a
 * 404), so a policy denial is the second, independent check and a 403 only for a state the binder cannot reach.
 */
class SavedInsightPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->canAccessProject($project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->canAccessProject($project);
    }

    public function view(User $user, SavedInsight $insight): bool
    {
        return $this->canAccessProjectId($user, (int) $insight->project_id);
    }

    public function update(User $user, SavedInsight $insight): bool
    {
        return $this->canAccessProjectId($user, (int) $insight->project_id);
    }

    public function delete(User $user, SavedInsight $insight): bool
    {
        return $this->canAccessProjectId($user, (int) $insight->project_id);
    }

    private function canAccessProjectId(User $user, int $projectId): bool
    {
        return $user->is_admin === true || $user->projects()->whereKey($projectId)->exists();
    }
}
