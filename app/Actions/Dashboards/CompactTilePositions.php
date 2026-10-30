<?php

namespace App\Actions\Dashboards;

use App\Models\DashboardTile;

/**
 * Renumbers the tiles of ONE dashboard 0..n-1 in their current order (position, then id). Only called by the actions
 * that change a dashboard's tiles, INSIDE their transaction and while they hold the lock on the dashboard row; it
 * writes only the tiles whose number actually changes.
 */
final class CompactTilePositions
{
    public function handle(int $dashboardId): void
    {
        $tiles = DashboardTile::query()->where('dashboard_id', $dashboardId)->orderBy('position')->orderBy('id')->get(['id', 'position']);

        foreach ($tiles->values() as $index => $tile) {
            if ($tile->position !== $index) {
                DashboardTile::query()->whereKey($tile->id)->update(['position' => $index]);
            }
        }
    }
}
