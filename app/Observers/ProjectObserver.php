<?php

namespace App\Observers;

use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Keeps ProjectResolver's cache honest when a project row changes through the model.
 *
 * Deliberately NOT a ShouldHandleEventsAfterCommit observer. Laravel would then defer `updated`
 * until after the commit, by which time the model has already synced its originals and the old
 * key is only recoverable from state captured in an un-deferred `updating` hook, and a model that
 * is re-keyed twice in one transaction (A -> B -> C) overwrites that state and leaks A. Instead
 * `created`/`updated`/`deleted` run immediately: `updated` fires inside Model::performUpdate()
 * BEFORE finishSave() calls syncOriginal(), so getOriginal('api_key') still holds the old key.
 * Each hook captures the keys it must forget in a closure and hands it to DB::afterCommit(), which
 * runs it at once when no transaction is open (the row is already committed), after the OUTERMOST
 * commit otherwise (forgetting earlier would let a concurrent request re-cache the old row), and
 * drops it on rollback.
 *
 * Query-builder mass operations (`Project::where(...)->delete()`, `saveQuietly()`) fire no model
 * events and are NOT covered: re-keying and deleting must go through the model.
 */
class ProjectObserver
{
    public function created(Project $project): void
    {
        // Drops a negative entry left by a probe of the key before the project existed.
        $this->forgetAfterCommit([$project->api_key]);
    }

    public function updated(Project $project): void
    {
        // Still dirty here: the originals are synced only after this event.
        if (! $project->isDirty('api_key')) {
            return;
        }

        $old = $project->getOriginal('api_key');
        if (! is_string($old)) {
            return;
        }

        $this->forgetAfterCommit([$old, $project->api_key]);
    }

    public function deleted(Project $project): void
    {
        $this->forgetAfterCommit([$project->api_key]);
    }

    /**
     * @param  list<string>  $apiKeys
     */
    private function forgetAfterCommit(array $apiKeys): void
    {
        DB::afterCommit(function () use ($apiKeys) {
            // One forget() per key: a failure on one must not skip the others. A cache outage must
            // never break a project save or delete, and ProjectResolver::forget() stays strict
            // (a silent failure would keep a deleted key valid for the positive TTL), so the
            // failure is reported instead of swallowed.
            foreach ($apiKeys as $apiKey) {
                try {
                    app(ProjectResolver::class)->forget($apiKey);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
    }
}
