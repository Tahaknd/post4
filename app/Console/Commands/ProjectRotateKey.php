<?php

namespace App\Console\Commands;

use App\Actions\Projects\RotateProjectKey;
use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProjectRotateKey extends Command
{
    protected $signature = 'project:rotate-key {id : Project id} {--key-only : Print only the new API key}';

    protected $description = 'Replaces a project\'s API key and drops the old key from the cache';

    public function handle(RotateProjectKey $rotateProjectKey, ProjectResolver $resolver): int
    {
        $id = (string) $this->argument('id');
        // Anything else (or a value beyond bigint) would reach Postgres as a query error.
        if (! ctype_digit($id) || strlen($id) > 18) {
            $this->getOutput()->getErrorStyle()->error('Invalid project id.');

            return self::FAILURE;
        }

        $project = Project::find($id);
        if ($project === null) {
            $this->getOutput()->getErrorStyle()->error('Project not found.');

            return self::FAILURE;
        }

        $oldKey = $project->api_key;

        try {
            $project = $rotateProjectKey->handle($project);
        } catch (ModelNotFoundException) {
            $this->getOutput()->getErrorStyle()->error('Project not found.');

            return self::FAILURE;
        }

        // The new key is already saved: print it first so a failing cache can never hide it.
        $this->option('key-only')
            ? $this->line($project->api_key)
            : $this->info("New API key for project #{$project->id}: {$project->api_key}");

        // The observer forgets the old key after the commit but only reports a cache failure; tell the operator,
        // since the cached entry would keep authorising the old key for up to the cache TTL.
        try {
            $resolver->forget($oldKey);
        } catch (\Throwable $e) {
            // stderr keeps `--key-only` stdout to just the key.
            $this->getOutput()->getErrorStyle()->warning('Could not clear the old key from the cache; it may still be accepted for up to '.ProjectResolver::POSITIVE_TTL_SECONDS.' seconds: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
