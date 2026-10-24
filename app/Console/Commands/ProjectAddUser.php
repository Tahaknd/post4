<?php

namespace App\Console\Commands;

use App\Actions\Projects\AddProjectMember;
use App\Console\Concerns\ResolvesProjects;
use App\Models\ProjectRole;
use App\Models\User;
use Illuminate\Console\Command;

class ProjectAddUser extends Command
{
    use ResolvesProjects;

    protected $signature = 'project:add-user {project : Project id} {email : Email address of an existing user} {--role=member : Role on the project (member)}';

    protected $description = 'Makes an existing user a member of a project. Exit codes: 0 added or already a member, 1 user not found, 2 invalid id or role, 3 project not found';

    public function handle(AddProjectMember $addProjectMember): int
    {
        $role = ProjectRole::tryFrom((string) $this->option('role'));
        if ($role === null) {
            return $this->failWith('Unknown role. Available: '.implode(', ', array_column(ProjectRole::cases(), 'value')).'.', self::INVALID);
        }

        $project = $this->projectFromIdArgument('project');
        if (is_int($project)) {
            return $project;
        }

        $user = User::query()->where('email', User::normaliseEmail((string) $this->argument('email')))->first();
        if ($user === null) {
            return $this->failWith('User not found.', self::FAILURE);
        }

        $created = $addProjectMember->handle($project, $user, $role);

        $this->info($created
            ? "Added {$user->email} to project #{$project->id} as {$role->value}."
            : "{$user->email} is already a member of project #{$project->id}.");

        return self::SUCCESS;
    }
}
