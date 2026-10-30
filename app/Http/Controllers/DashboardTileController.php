<?php

namespace App\Http\Controllers;

use App\Actions\Dashboards\AddTileToDashboard;
use App\Actions\Dashboards\RemoveTile;
use App\Actions\Dashboards\ReorderTiles;
use App\Domain\Dashboards\SavedQueryResolver;
use App\Http\Requests\Dashboards\AddTileRequest;
use App\Http\Requests\Dashboards\ReorderTilesRequest;
use App\Http\Resources\DashboardResource;
use App\Models\Dashboard;
use App\Models\DashboardTile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The tiles of a dashboard. `{tile}` is bound inside its dashboard (a tile of another dashboard is a 404). Changing tiles is
 * `update` on the dashboard (DashboardPolicy).
 *
 *   POST   /api/projects/{project}/dashboards/{dashboard}/tiles          {saved_insight_id}: 201 the new tile, or 200 the
 *                                                                        existing one (adding is idempotent)
 *   DELETE /api/projects/{project}/dashboards/{dashboard}/tiles/{tile}   204; the saved insight stays
 *   PUT    /api/projects/{project}/dashboards/{dashboard}/tiles/order    {tiles: [tile ids]}: exactly the current set, in the
 *                                                                        new order; 200 the tiles
 */
class DashboardTileController extends Controller
{
    public function store(AddTileRequest $request, Project $project, Dashboard $dashboard, AddTileToDashboard $add, SavedQueryResolver $resolver): JsonResponse
    {
        $added = $add->handle($dashboard, $request->savedInsight(), $this->user($request));
        $tile = $added['tile']->load('savedInsight.creator:id,name');

        return response()->json(['data' => DashboardResource::tile($tile, $resolver, now())], $added['created'] ? 201 : 200);
    }

    public function destroy(Request $request, Project $project, Dashboard $dashboard, DashboardTile $tile, RemoveTile $remove): Response
    {
        Gate::authorize('update', $dashboard);

        $remove->handle($tile, $this->user($request));

        return response()->noContent();
    }

    public function order(ReorderTilesRequest $request, Project $project, Dashboard $dashboard, ReorderTiles $reorder, SavedQueryResolver $resolver): JsonResponse
    {
        $tiles = $reorder->handle($dashboard, $request->order(), $this->user($request))->load('savedInsight.creator:id,name');
        $now = now();

        return response()->json(['data' => $tiles->map(fn (DashboardTile $tile) => DashboardResource::tile($tile, $resolver, $now))->all()]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
