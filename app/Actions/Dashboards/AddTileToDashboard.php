<?php

namespace App\Actions\Dashboards;

use App\Domain\Dashboards\DashboardAudit;
use App\Domain\Dashboards\Limits;
use App\Models\Dashboard;
use App\Models\DashboardTile;
use App\Models\SavedInsight;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Puts a saved insight at the END of a dashboard. Idempotent: an insight that is already on the dashboard is returned as
 * it is (`created` false) and nothing changes. Everything happens under a lock on the dashboard row, which is also what
 * serialises the other tile actions of that dashboard: two concurrent adds see each other, so the limit is exact and the
 * position is never duplicated. The insight must belong to the dashboard's project (the composite foreign key says the
 * same, but a validation answer is better than a constraint error) and is locked in share mode until commit, so it cannot
 * be deleted halfway.
 */
final class AddTileToDashboard
{
    /**
     * @return array{tile: DashboardTile, created: bool}
     *
     * @throws ValidationException the insight is not in this project, or the dashboard is full
     * @throws ModelNotFoundException the dashboard was deleted meanwhile
     */
    public function handle(Dashboard $dashboard, SavedInsight $insight, ?User $actor = null): array
    {
        return DB::transaction(function () use ($dashboard, $insight, $actor): array {
            $locked = Dashboard::query()->whereKey($dashboard->getKey())->lockForUpdate()->firstOrFail();

            $source = SavedInsight::query()->whereKey($insight->getKey())->where('project_id', $locked->project_id)->sharedLock()->first();
            if ($source === null) {
                throw ValidationException::withMessages(['saved_insight_id' => 'This saved insight does not exist in this project.']);
            }

            $existing = DashboardTile::query()->where('dashboard_id', $locked->id)->where('saved_insight_id', $source->id)->first();
            if ($existing !== null) {
                return ['tile' => $existing, 'created' => false];
            }

            if (DashboardTile::query()->where('dashboard_id', $locked->id)->count() >= Limits::tiles()) {
                throw ValidationException::withMessages(['limit' => 'A dashboard can have at most '.Limits::tiles().' tiles. Remove one first.']);
            }

            $last = DashboardTile::query()->where('dashboard_id', $locked->id)->max('position');

            $tile = DashboardTile::query()->create([
                'uuid' => (string) Str::uuid7(),
                'project_id' => $locked->project_id,
                'dashboard_id' => $locked->id,
                'saved_insight_id' => $source->id,
                'position' => $last === null ? 0 : ((int) $last) + 1,
            ]);

            DashboardAudit::record('dashboard.tile_add', $actor, (int) $locked->project_id, ['dashboard' => $locked->uuid, 'saved_insight' => $source->uuid]);

            return ['tile' => $tile, 'created' => true];
        });
    }
}
