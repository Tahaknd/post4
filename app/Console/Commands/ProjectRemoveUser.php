<?php

namespace App\Console\Commands;

use App\Actions\Projects\RemoveProjectMember;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;

class ProjectRemoveUser extends Command
{
    protected $signature = 'project:remove-user {project : Project id} {email : Email address of the member}';

    protected $description = 'Removes a user\'s membership of a project (administrators keep reaching every project)';

    public function handle(RemoveProjectMember $removeProjectMember): int
    {
        $id = (string) $this->argument('project');
        if (! ctype_digit($id) || strlen($id) > 18) {
            $this->getOutput()->getErrorStyle()->error('Invalid project id.');

            return self::FAILURE;
        }

        $project = Project::find($id);
        if ($project === null) {
            $this->getOutput()->getErrorStyle()->error('Project not found.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', User::normaliseEmail((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->getOutput()->getErrorStyle()->error('User not found.');

            return self::FAILURE;
        }

        $this->info($removeProjectMember->handle($project, $user)
            ? "Removed {$user->email} from project #{$project->id}."
            : "{$user->email} is not a member of project #{$project->id}.");

        return self::SUCCESS;
    }
}
