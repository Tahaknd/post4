<?php

namespace App\Actions\Dashboards;

use App\Domain\Dashboards\DashboardAudit;
use App\Models\Dashboard;
use App\Models\DashboardTile;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the order of a dashboard's tiles. The list must be EXACTLY the current set (every tile once, no stranger): a client
 * that reorders a stale view, after another one added or removed a tile, is told (422) instead of silently losing or
 * resurrecting one. Under the dashboard row lock, so concurrent reorders apply one after the other and the positions
 * are always 0..n-1 without duplicates.
 */
final class ReorderTiles
{
    /**
     * @param  list<string>  $orderedTileUuids
     * @return Collection<int, DashboardTile> the tiles in their new order
     *
     * @throws ValidationException
     * @throws ModelNotFoundException the dashboard was deleted meanwhile
     */
    public function handle(Dashboard $dashboard, array $orderedTileUuids, ?User $actor = null): Collection
    {
        return DB::transaction(function () use ($dashboard, $orderedTileUuids, $actor): Collection {
            $locked = Dashboard::query()->whereKey($dashboard->getKey())->lockForUpdate()->firstOrFail();

            $current = DashboardTile::query()->where('dashboard_id', $locked->id)->get()->keyBy('uuid');

            $given = array_values($orderedTileUuids);
            $sameSet = count($given) === count(array_unique($given))
                && count($given) === $current->count()
                && array_diff($given, $current->keys()->all()) === [];
            if (! $sameSet) {
                throw ValidationException::withMessages(['tiles' => 'List every tile of the dashboard exactly once. The dashboard may have changed: reload it.']);
            }

            foreach ($given as $position => $uuid) {
                $tile = $current[$uuid];
                if ($tile->position !== $position) {
                    DashboardTile::query()->whereKey($tile->id)->update(['position' => $position]);
                    $tile->position = $position;
                }
            }

            DashboardAudit::record('dashboard.tiles_reorder', $actor, (int) $locked->project_id, ['dashboard' => $locked->uuid]);

            return new Collection(array_map(fn (string $uuid) => $current[$uuid], $given));
        });
    }
}
