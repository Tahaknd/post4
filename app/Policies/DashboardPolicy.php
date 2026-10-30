<?php

namespace App\Policies;

use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;

/**
 * Who may do what with dashboards and their tiles: the same rule as {@see SavedInsightPolicy}, i.e. an administrator or a
 * member of the project. Tile changes are `update` on the dashboard.
 */
class DashboardPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->canAccessProject($project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->canAccessProject($project);
    }

    public function view(User $user, Dashboard $dashboard): bool
    {
        return $this->canAccessProjectId($user, (int) $dashboard->project_id);
    }

    public function update(User $user, Dashboard $dashboard): bool
    {
        return $this->canAccessProjectId($user, (int) $dashboard->project_id);
    }

    public function delete(User $user, Dashboard $dashboard): bool
    {
        return $this->canAccessProjectId($user, (int) $dashboard->project_id);
    }

    private function canAccessProjectId(User $user, int $projectId): bool
    {
        return $user->is_admin === true || $user->projects()->whereKey($projectId)->exists();
    }
}
