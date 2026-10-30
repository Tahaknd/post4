<?php

namespace App\Actions\SavedInsights;

use App\Actions\Dashboards\CompactTilePositions;
use App\Domain\Dashboards\DashboardAudit;
use App\Models\Dashboard;
use App\Models\DashboardTile;
use App\Models\SavedInsight;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a saved insight; its tiles go with it (foreign key) and the dashboards that showed it close the gap. Locks
 * those dashboards first, in id order (the order every tile action uses for its single lock), so a concurrent tile
 * write and this delete cannot deadlock. Deleting twice is not an error.
 */
final class DeleteSavedInsight
{
    public function __construct(private readonly CompactTilePositions $compact) {}

    public function handle(SavedInsight $insight, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($insight, $actor): bool {
            $dashboardIds = DashboardTile::query()->where('saved_insight_id', $insight->getKey())->orderBy('dashboard_id')->pluck('dashboard_id')->all();
            if ($dashboardIds !== []) {
                Dashboard::query()->whereIn('id', $dashboardIds)->orderBy('id')->lockForUpdate()->get(['id']);
            }

            $deleted = SavedInsight::query()->whereKey($insight->getKey())->delete() > 0;
            if (! $deleted) {
                return false;
            }

            foreach ($dashboardIds as $dashboardId) {
                $this->compact->handle((int) $dashboardId);
            }

            DashboardAudit::record('saved_insight.delete', $actor, (int) $insight->project_id, ['saved_insight' => $insight->uuid]);

            return true;
        });
    }
}
