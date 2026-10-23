<?php

namespace App\Console\Commands;

use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Console\Command;

class ProjectDelete extends Command
{
    /** Exit code for a well-formed API key that matches no project (scripts treat it as "already gone"). */
    public const NOT_FOUND = 3;

    protected $signature = 'project:delete {api_key : API key of the project to delete} {--force : Skip the confirmation prompt (non-interactive runs never prompt)}';

    protected $description = 'Deletes a project by API key (its ClickHouse events are kept). Exit codes: 0 deleted, 1 failure or aborted, 2 invalid key format, 3 unknown key';

    protected $help = <<<'HELP'
Deletes the project with the given API key. Its events stay in ClickHouse.

An interactive run asks for confirmation unless --force is given; a non-interactive run
(-n/--no-interaction, docker compose exec -T, a pipe) never prompts.

Exit codes (a stable contract for scripts):
  0  the project was deleted
  1  any other failure, or the confirmation was declined
  2  the API key has an invalid format (nothing was looked up)
  3  no project has this API key
Errors and warnings go to stderr; stdout carries only the result.
HELP;

    public function handle(ProjectResolver $resolver): int
    {
        $apiKey = (string) $this->argument('api_key');
        if (preg_match(Project::API_KEY_PATTERN, $apiKey) !== 1) {
            return $this->failWith('Invalid API key format.', self::INVALID);
        }

        $project = Project::where('api_key', $apiKey)->first();
        if ($project === null) {
            return $this->failWith('Project not found.', self::NOT_FOUND);
        }

        if (! $this->option('force')
            && $this->input->isInteractive()
            && ! $this->confirm("Delete project #{$project->id} \"{$project->name}\"? Its events remain in ClickHouse.", false)) {
            return $this->failWith('Aborted.', self::FAILURE);
        }

        // Through the model (not a query-builder delete) so the observer fires and forgets the cache.
        $project->delete();

        $this->info("Project #{$project->id} deleted.");
        $this->line('NOTE: its events remain in ClickHouse (a purge option comes later).');

        // The observer already forgot the key but only reports a cache failure; mirror
        // project:rotate-key and tell the operator, since the key may stay valid in the cache.
        try {
            $resolver->forget($apiKey);
        } catch (\Throwable $e) {
            // stderr keeps stdout clean for scripts.
            $this->getOutput()->getErrorStyle()->warning('Could not clear the cached key; it may still be accepted for up to '.ProjectResolver::POSITIVE_TTL_SECONDS.' seconds: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    /** Errors go to stderr so stdout stays reserved for results. */
    private function failWith(string $message, int $exitCode): int
    {
        $this->getOutput()->getErrorStyle()->error($message);

        return $exitCode;
    }
}
