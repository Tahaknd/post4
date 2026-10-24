<?php

namespace App\Domain\Projects;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One structured log line per project write, emitted only once the change is committed (a rolled-back write
 * leaves no line). Carries who (a user id, or "cli" for an artisan command), which project and what happened;
 * never the API key, a name or anything else a reader of the log could abuse.
 */
final class ProjectAudit
{
    public const string CLI = 'cli';

    /**
     * @param  array<string, scalar>  $context  extra non-sensitive facts, e.g. ['purge_events' => true]
     */
    public static function record(string $action, ?User $actor, int $projectId, array $context = []): void
    {
        DB::afterCommit(function () use ($action, $actor, $projectId, $context): void {
            Log::info('project.'.$action, [
                'actor' => $actor?->getKey() ?? self::CLI,
                'project_id' => $projectId,
                'action' => $action,
                ...$context,
            ]);
        });
    }

    private function __construct() {}
}
