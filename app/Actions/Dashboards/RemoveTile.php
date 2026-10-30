<?php

namespace App\Actions\Dashboards;

use App\Domain\Dashboards\DashboardAudit;
use App\Models\Dashboard;
use App\Models\DashboardTile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Takes one tile off its dashboard (the saved insight stays) and closes the gap. Under the dashboard row lock. */
final class RemoveTile
{
    public function __construct(private readonly CompactTilePositions $compact) {}

    /** @return bool false when the tile (or its dashboard) was already gone */
    public function handle(DashboardTile $tile, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($tile, $actor): bool {
            $dashboard = Dashboard::query()->whereKey($tile->dashboard_id)->lockForUpdate()->first();
            if ($dashboard === null) {
                return false;
            }

            if (DashboardTile::query()->whereKey($tile->getKey())->delete() === 0) {
                return false;
            }

            $this->compact->handle((int) $dashboard->id);
            DashboardAudit::record('dashboard.tile_remove', $actor, (int) $dashboard->project_id, ['dashboard' => $dashboard->uuid, 'tile' => $tile->uuid]);

            return true;
        });
    }
}
