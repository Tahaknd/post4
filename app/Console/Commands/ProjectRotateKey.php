<?php

namespace App\Console\Commands;

use App\Actions\Projects\RotateProjectKey;
use App\Console\Concerns\ResolvesProjects;
use App\Domain\Capture\ProjectResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProjectRotateKey extends Command
{
    use ResolvesProjects;

    protected $signature = 'project:rotate-key {id : Project id} {--key-only : Print only the new API key}';

    protected $description = 'Replaces a project\'s API key and drops the old key from the cache. Exit codes: 0 rotated, 2 invalid id, 3 project not found';

    public function handle(RotateProjectKey $rotateProjectKey, ProjectResolver $resolver): int
    {
        $project = $this->projectFromIdArgument('id');
        if (is_int($project)) {
            return $project;
        }

        $oldKey = $project->api_key;

        try {
            $project = $rotateProjectKey->handle($project);
        } catch (ModelNotFoundException) {
            return $this->failWith('Project not found.', self::NOT_FOUND);
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
            $this->warnOnStderr('Could not clear the old key from the cache; it may still be accepted for up to '.ProjectResolver::POSITIVE_TTL_SECONDS.' seconds: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
