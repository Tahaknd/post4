<?php

namespace App\Http\Controllers;

use App\Actions\Dashboards\CreateDashboard;
use App\Actions\Dashboards\DeleteDashboard;
use App\Actions\Dashboards\RecordDashboardView;
use App\Actions\Dashboards\UpdateDashboard;
use App\Domain\Dashboards\SavedQueryResolver;
use App\Http\Requests\Dashboards\ListRequest;
use App\Http\Requests\Dashboards\StoreDashboardRequest;
use App\Http\Requests\Dashboards\UpdateDashboardRequest;
use App\Http\Resources\DashboardResource;
use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The dashboard JSON API (tiles: {@see DashboardTileController}). `{project}` and `{dashboard}` are bound by
 * AppServiceProvider (a dashboard of another project is a 404; the URL carries the uuid), every action is authorised by
 * DashboardPolicy, every write is one call to an action.
 *
 *   GET    /api/projects/{project}/dashboards                   list (page, per_page) with tiles_count
 *   POST   /api/projects/{project}/dashboards                   201 the new, empty dashboard
 *   GET    /api/projects/{project}/dashboards/{dashboard}       the dashboard with its tiles in position order, each with the
 *                                                               summary of its saved insight; also records `last_viewed_at`
 *                                                               (at most once per dashboards.view_touch_seconds), which keeps
 *                                                               the dashboard in the scheduled refresh
 *   PATCH  /api/projects/{project}/dashboards/{dashboard}       name and/or description
 *   DELETE /api/projects/{project}/dashboards/{dashboard}       204; its tiles go, the saved insights stay
 */
class DashboardController extends Controller
{
    public function index(ListRequest $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [Dashboard::class, $project]);

        $page = Dashboard::query()
            ->where('project_id', $project->getKey())
            ->withCount('tiles')
            ->with('creator:id,name')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Dashboard $dashboard) => DashboardResource::summary($dashboard))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(StoreDashboardRequest $request, Project $project, CreateDashboard $create, SavedQueryResolver $resolver): JsonResponse
    {
        $dashboard = $create->handle($project, (string) $request->validated('name'), $request->validated('description'), $this->user($request));

        return response()->json(['data' => DashboardResource::detail($this->loaded($dashboard), $resolver, now())], 201);
    }

    public function show(Request $request, Project $project, Dashboard $dashboard, SavedQueryResolver $resolver, RecordDashboardView $recordView): JsonResponse
    {
        Gate::authorize('view', $dashboard);

        $recordView->handle($dashboard);

        return response()->json(['data' => DashboardResource::detail($this->loaded($dashboard), $resolver, now())]);
    }

    public function update(UpdateDashboardRequest $request, Project $project, Dashboard $dashboard, UpdateDashboard $update, SavedQueryResolver $resolver): JsonResponse
    {
        $updated = $update->handle($dashboard, $request->changes(), $this->user($request));

        return response()->json(['data' => DashboardResource::detail($this->loaded($updated), $resolver, now())]);
    }

    public function destroy(Request $request, Project $project, Dashboard $dashboard, DeleteDashboard $delete): Response
    {
        Gate::authorize('delete', $dashboard);

        $delete->handle($dashboard, $this->user($request));

        return response()->noContent();
    }

    /** The dashboard with its creator and its tiles (position order) with their insights, in three queries. */
    private function loaded(Dashboard $dashboard): Dashboard
    {
        return $dashboard->load(['creator:id,name', 'tiles.savedInsight.creator:id,name']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
