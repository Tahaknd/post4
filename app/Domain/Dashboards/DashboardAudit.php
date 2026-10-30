<?php

namespace App\Domain\Dashboards;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One structured log line per write of a saved insight or a dashboard, emitted once the change is committed (a
 * rolled-back write leaves no line): `dashboard.create`, `saved_insight.update`, ... with who (a user id, or `cli`),
 * the project, and ids only: never a name, a description or a query. Same channel rule as ProjectAudit.
 */
final class DashboardAudit
{
    /** @param  array<string, scalar>  $context */
    public static function record(string $event, ?User $actor, int $projectId, array $context = []): void
    {
        DB::afterCommit(function () use ($event, $actor, $projectId, $context): void {
            $fields = ['actor' => $actor?->getKey() ?? 'cli', 'project_id' => $projectId, ...$context];
            $channel = config('logging.audit_channel');

            if (is_string($channel) && $channel !== '') {
                Log::channel($channel)->info($event, $fields);
            } else {
                Log::info($event, $fields);
            }
        });
    }

    private function __construct() {}
}
