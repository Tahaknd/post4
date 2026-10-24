<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectList extends Command
{
    protected $signature = 'project:list {--json : Print one JSON line with an array of projects} {--show-keys : Include the API keys (never printed otherwise)}';

    protected $description = 'Lists projects (id, name, timezone, creation time, explicit member count)';

    protected $help = <<<'HELP'
Lists every project. API keys are secrets and are NOT printed unless --show-keys is given.
"members" counts explicit project_user memberships; administrators reach every project without one.
HELP;

    public function handle(): int
    {
        $showKeys = (bool) $this->option('show-keys');

        $rows = Project::query()->withCount('users')->orderBy('id')->get()->map(fn (Project $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'timezone' => $project->timezone,
            'created_at' => $project->created_at?->toIso8601String(),
            'members' => $project->users_count,
            ...($showKeys ? ['api_key' => $project->api_key] : []),
        ])->all();

        if ($this->option('json')) {
            $this->output->writeln(json_encode($rows, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('No projects.');

            return self::SUCCESS;
        }

        $this->table(array_keys($rows[0]), $rows);

        return self::SUCCESS;
    }
}
