<?php

namespace App\Console\Commands;

use App\Actions\Projects\CreateProject;
use App\Domain\Projects\ProjectRules;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectCreate extends Command
{
    protected $signature = 'project:create {name : Project name (1-100 characters)} {--timezone=UTC : IANA timezone, e.g. Europe/Istanbul} {--key-only : Print only the API key} {--json : Print one JSON line: {"id":N,"api_key":"phc_..."}}';

    protected $description = 'Creates a project and its API key';

    public function handle(CreateProject $createProject): int
    {
        if ($this->option('json') && $this->option('key-only')) {
            // stderr: stdout is reserved for the result.
            $this->getOutput()->getErrorStyle()->error('--json and --key-only cannot be used together.');

            return self::INVALID;
        }

        try {
            $project = $createProject->handle((string) $this->argument('name'), (string) ($this->option('timezone') ?: ProjectRules::DEFAULT_TIMEZONE));
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $message) {
                $this->getOutput()->getErrorStyle()->error($message);
            }

            return self::INVALID;
        }

        if ($this->option('json')) {
            // Raw: the formatter must never interpret anything in the payload (and `-q` prints nothing, by design).
            $this->output->writeln(json_encode(['id' => $project->id, 'api_key' => $project->api_key], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } elseif ($this->option('key-only')) {
            $this->line($project->api_key);
        } else {
            $this->info("Project #{$project->id} created. API key: {$project->api_key}");
        }

        return self::SUCCESS;
    }
}
