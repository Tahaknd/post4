<?php

namespace App\Http\Resources;

use App\Domain\Dashboards\SavedQueryResolver;
use App\Models\Dashboard;
use App\Models\DashboardTile;
use DateTimeInterface;

/**
 * The JSON of a dashboard. The list carries `tiles_count`; the detail carries the tiles in position order, each with the
 * SUMMARY of its saved insight (name, type, state ... but not the query: a tile is run through its own endpoint). A tile
 * of an insight that `needs_review` is still listed, marked by its state.
 */
final class DashboardResource
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function summary(Dashboard $dashboard): array
    {
        return [
            'id' => $dashboard->uuid,
            'name' => $dashboard->name,
            'description' => $dashboard->description,
            'tiles_count' => $dashboard->tiles_count ?? $dashboard->tiles()->count(),
            'last_viewed_at' => $dashboard->last_viewed_at?->toIso8601String(),
            'created_by' => $dashboard->creator?->name,
            'created_at' => $dashboard->created_at?->toIso8601String(),
            'updated_at' => $dashboard->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> the dashboard with its tiles loaded (`tiles.savedInsight.creator`) */
    public static function detail(Dashboard $dashboard, SavedQueryResolver $resolver, DateTimeInterface $now): array
    {
        $tiles = $dashboard->tiles->map(fn ($tile) => self::tile($tile, $resolver, $now))->all();

        return [...self::summary($dashboard), 'tiles_count' => count($tiles), 'tiles' => $tiles];
    }

    /** @return array<string, mixed> */
    public static function tile(DashboardTile $tile, SavedQueryResolver $resolver, DateTimeInterface $now): array
    {
        return [
            'id' => $tile->uuid,
            'position' => $tile->position,
            'saved_insight' => SavedInsightResource::summary($tile->savedInsight, $resolver, $now),
        ];
    }
}
