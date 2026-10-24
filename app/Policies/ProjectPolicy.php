<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Who may do what with projects. Every ability is a decision about a signed-in user (guests never reach a
 * policy: the routes sit behind `auth`).
 *
 *  - viewAny / view / update / rotateKey: an administrator, or a member of the project (User::canAccessProject).
 *  - create: ANY signed-in user. Projects are self-service on a self-hosted install; the creator becomes the
 *    member of what they create (CreateProject), an administrator needs no membership row. If creation should
 *    ever be restricted, this is the one line to change.
 *  - delete, manageMembers: administrators only. A member can use and re-key a project, but deleting it destroys
 *    its events, and who is a member is the administrator's call.
 *
 * Route model binding already limits `{project}` to Project::visibleTo(user), so a non-member gets a 404 before
 * any of this runs; the policy is the second, independent check.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->canAccessProject($project);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Project $project): bool
    {
        return $user->canAccessProject($project);
    }

    public function rotateKey(User $user, Project $project): bool
    {
        return $user->canAccessProject($project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->is_admin === true;
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $user->is_admin === true;
    }
}
