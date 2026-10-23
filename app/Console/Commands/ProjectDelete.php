<?php

namespace App\Console\Commands;

use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Console\Command;

class ProjectDelete extends Command
{
    protected $signature = 'project:delete {api_key : API key of the project to delete} {--force : Accepted for scripts; there is never a prompt}';

    protected $description = 'Deletes a project by API key, immediately and without a prompt (its ClickHouse events are kept)';

    public function handle(ProjectResolver $resolver): int
    {
        $apiKey = (string) $this->argument('api_key');
        if (preg_match(Project::API_KEY_PATTERN, $apiKey) !== 1) {
            $this->error('Invalid API key format.');

            return self::FAILURE;
        }

        $project = Project::where('api_key', $apiKey)->first();
        if ($project === null) {
            $this->error('Project not found.');

            return self::FAILURE;
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
}
