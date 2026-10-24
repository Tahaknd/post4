<?php

namespace App\Actions\Projects;

use App\Domain\Projects\ProjectAudit;
use App\Domain\Projects\ProjectRules;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only place that inserts a project (the arch test in tests/Unit/ProjectWritesArchTest.php keeps it so).
 * Validates what it is given, so a command, a controller or a future API cannot store a bad project.
 */
final class CreateProject
{
    /**
     * A non-admin creator becomes a member (otherwise they could not open what they just made); an admin
     * reaches every project and needs no membership row. $actor null means an artisan command.
     *
     * @throws ValidationException
     */
    public function handle(string $name, ?string $timezone = null, ?User $actor = null): Project
    {
        $data = Validator::make(
            ['name' => trim($name), 'timezone' => $timezone ?? ProjectRules::DEFAULT_TIMEZONE],
            ['name' => ProjectRules::name(), 'timezone' => ProjectRules::timezone()],
        )->validate();

        return DB::transaction(function () use ($data, $actor): Project {
            $project = Project::create([
                'name' => $data['name'],
                'api_key' => Project::generateApiKey(),
                'timezone' => $data['timezone'],
            ]);

            if ($actor !== null && ! $actor->is_admin) {
                $project->users()->attach($actor->getKey(), ['role' => ProjectRole::Member->value]);
            }

            ProjectAudit::record('create', $actor, $project->getKey());

            return $project;
        });
    }
}
