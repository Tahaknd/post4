<?php

namespace App\Console\Commands;

use App\Actions\Projects\DeleteProject;
use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Console\Command;

class ProjectDelete extends Command
{
    /** Exit code for a well-formed API key that matches no project (scripts treat it as "already gone"). */
    public const NOT_FOUND = 3;

    protected $signature = 'project:delete
        {api_key : API key of the project to delete}
        {--purge-events : Also delete the project\'s events from ClickHouse (queued; they are kept without this option)}
        {--force : Skip the confirmation prompt (non-interactive runs never prompt)}';

    protected $description = 'Deletes a project by API key; --purge-events also removes its ClickHouse events. Exit codes: 0 deleted, 1 failure or aborted, 2 invalid key format, 3 unknown key';

    protected $help = <<<'HELP'
Deletes the project with the given API key.

Without --purge-events the project's events STAY in ClickHouse, orphaned (nothing can read them any more).
With --purge-events a queue job deletes them (and runs once more ten minutes later for events that were still
in flight); the Horizon worker must be running for that.

An interactive run asks for confirmation unless --force is given; a non-interactive run
(-n/--no-interaction, docker compose exec -T, a pipe) never prompts.

Exit codes (a stable contract for scripts):
  0  the project was deleted
  1  any other failure, or the confirmation was declined
  2  the API key has an invalid format (nothing was looked up)
  3  no project has this API key
Errors and warnings go to stderr; stdout carries only the result.
HELP;

    public function handle(DeleteProject $deleteProject, ProjectResolver $resolver): int
    {
        $apiKey = (string) $this->argument('api_key');
        if (preg_match(Project::API_KEY_PATTERN, $apiKey) !== 1) {
            return $this->failWith('Invalid API key format.', self::INVALID);
        }

        $project = Project::where('api_key', $apiKey)->first();
        if ($project === null) {
            return $this->failWith('Project not found.', self::NOT_FOUND);
        }

        $purge = (bool) $this->option('purge-events');

        if (! $this->option('force')
            && $this->input->isInteractive()
            && ! $this->confirm($this->prompt($project, $purge), false)) {
            return $this->failWith('Aborted.', self::FAILURE);
        }

        $deleteProject->handle($project, purgeEvents: $purge);

        $this->info("Project #{$project->id} deleted.");

        if ($purge) {
            $this->line('Events purge queued (a second pass follows in '.intdiv(DeleteProject::SECOND_PASS_DELAY_SECONDS, 60).' minutes). The Horizon worker must be running.');
        } else {
            $this->getOutput()->getErrorStyle()->warning("Events of project #{$project->id} were KEPT in ClickHouse and are now orphaned. Run project:delete with --purge-events next time to remove them.");
        }

        // The observer already forgot the key but only reports a cache failure; tell the operator,
        // since the key may stay valid in the cache.
        try {
            $resolver->forget($apiKey);
        } catch (\Throwable $e) {
            // stderr keeps stdout clean for scripts.
            $this->getOutput()->getErrorStyle()->warning('Could not clear the cached key; it may still be accepted for up to '.ProjectResolver::POSITIVE_TTL_SECONDS.' seconds: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    public function prompt(Project $project, bool $purge): string
    {
        return "Delete project #{$project->id} \"{$project->name}\"? ".($purge
            ? 'Its events will be deleted from ClickHouse too.'
            : 'Its events remain in ClickHouse.');
    }

    /** Errors go to stderr so stdout stays reserved for results. */
    private function failWith(string $message, int $exitCode): int
    {
        $this->getOutput()->getErrorStyle()->error($message);

        return $exitCode;
    }
}
