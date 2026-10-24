<?php

namespace App\Console\Commands;

use App\Actions\Projects\RemoveProjectMember;
use App\Console\Concerns\ResolvesProjects;
use App\Models\User;
use Illuminate\Console\Command;

class ProjectRemoveUser extends Command
{
    use ResolvesProjects;

    protected $signature = 'project:remove-user {project : Project id} {email : Email address of the member}';

    protected $description = 'Removes a user\'s membership of a project (administrators keep reaching every project). Exit codes: 0 removed or not a member, 1 user not found, 2 invalid id, 3 project not found';

    public function handle(RemoveProjectMember $removeProjectMember): int
    {
        $project = $this->projectFromIdArgument('project');
        if (is_int($project)) {
            return $project;
        }

        $user = User::query()->where('email', User::normaliseEmail((string) $this->argument('email')))->first();
        if ($user === null) {
            return $this->failWith('User not found.', self::FAILURE);
        }

        $this->info($removeProjectMember->handle($project, $user)
            ? "Removed {$user->email} from project #{$project->id}."
            : "{$user->email} is not a member of project #{$project->id}.");

        return self::SUCCESS;
    }
}
