<?php

namespace App\Observers;

use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use WeakMap;

/**
 * Keeps ProjectResolver's cache honest when a project row changes through the model.
 *
 * Query-builder mass operations (`Project::where(...)->delete()`) fire no model events and
 * are NOT covered: re-keying and deleting must go through the model.
 *
 * ShouldHandleEventsAfterCommit defers created/updated/deleted until the surrounding transaction
 * commits (immediately when there is none) and drops them on rollback; `updating` is never
 * deferred.
 */
class ProjectObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Old api keys of models about to be re-keyed. Each event gets its own observer instance and a
     * deferred `updated` runs after the model synced its originals, so getOriginal() would already
     * return the new key by then.
     *
     * @var WeakMap<Project, string>|null
     */
    private static ?WeakMap $rekeyed = null;

    public function updating(Project $project): void
    {
        if ($project->isDirty('api_key') && is_string($old = $project->getOriginal('api_key'))) {
            self::$rekeyed ??= new WeakMap;
            self::$rekeyed[$project] = $old;
        }
    }

    public function created(Project $project): void
    {
        // Drops a negative entry left by a probe of the key before the project existed.
        $this->forget($project->api_key);
    }

    public function updated(Project $project): void
    {
        $old = self::$rekeyed[$project] ?? null;
        if ($old === null) {
            return;
        }
        unset(self::$rekeyed[$project]);

        // Separate calls so a failure on one key cannot skip the other.
        $this->forget($old);
        $this->forget($project->api_key);
    }

    public function deleted(Project $project): void
    {
        $this->forget($project->api_key);
    }

    /**
     * A cache outage must never break a project save or delete. ProjectResolver::forget() stays
     * strict (a silent failure would keep a deleted key valid for the positive TTL), so the
     * failure is reported instead of swallowed.
     */
    private function forget(string $apiKey): void
    {
        try {
            app(ProjectResolver::class)->forget($apiKey);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
