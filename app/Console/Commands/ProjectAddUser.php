<?php

namespace App\Console\Commands;

use App\Actions\Projects\AddProjectMember;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use Illuminate\Console\Command;

class ProjectAddUser extends Command
{
    protected $signature = 'project:add-user {project : Project id} {email : Email address of an existing user} {--role=member : Role on the project (member)}';

    protected $description = 'Makes an existing user a member of a project';

    public function handle(AddProjectMember $addProjectMember): int
    {
        $role = ProjectRole::tryFrom((string) $this->option('role'));
        if ($role === null) {
            $this->getOutput()->getErrorStyle()->error('Unknown role. Available: '.implode(', ', array_column(ProjectRole::cases(), 'value')).'.');

            return self::INVALID;
        }

        $project = $this->project();
        if ($project === null) {
            return self::FAILURE;
        }

        $user = User::query()->where('email', User::normaliseEmail((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->getOutput()->getErrorStyle()->error('User not found.');

            return self::FAILURE;
        }

        $created = $addProjectMember->handle($project, $user, $role);

        $this->info($created
            ? "Added {$user->email} to project #{$project->id} as {$role->value}."
            : "{$user->email} is already a member of project #{$project->id}.");

        return self::SUCCESS;
    }

    private function project(): ?Project
    {
        $id = (string) $this->argument('project');
        // Anything else (or a value beyond bigint) would reach Postgres as a query error.
        if (! ctype_digit($id) || strlen($id) > 18) {
            $this->getOutput()->getErrorStyle()->error('Invalid project id.');

            return null;
        }

        $project = Project::find($id);
        if ($project === null) {
            $this->getOutput()->getErrorStyle()->error('Project not found.');
        }

        return $project;
    }
}
