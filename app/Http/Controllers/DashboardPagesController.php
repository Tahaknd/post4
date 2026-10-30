<?php

namespace App\Http\Controllers;

use App\Domain\Dashboards\Limits;
use App\Http\DashboardLinks;
use App\Models\Dashboard;
use App\Models\Project;
use App\Models\SavedInsight;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pages of saved insights and dashboards: the dashboard list (`GET /projects/{project}/dashboards`), one dashboard
 * (`.../dashboards/{dashboard}`) and the saved insight list (`.../saved-insights`). `{project}` is bound through
 * Project::visibleTo(user) (404 for one the user cannot open), `{dashboard}` INSIDE that project by its uuid (a foreign or
 * unknown one is a 404 too), and the policies are the independent second check.
 *
 * The pages carry no data: they read and write through the JSON API (the same session, CSRF token and policies), so a page
 * and the API can never disagree. What they carry is where to talk (server-built paths, see {@see DashboardLinks}), the
 * limits to mirror for instant feedback, and for the dashboard the id and name (the tab title and the first paint). No key.
 */
class DashboardPagesController extends Controller
{
    public function index(Project $project): Response
    {
        Gate::authorize('viewAny', [Dashboard::class, $project]);

        return Inertia::render('Projects/Dashboards/Index', [
            'project' => self::project($project),
            'links' => DashboardLinks::for($project),
            'limits' => ['max_dashboards' => Limits::dashboards(), 'page_size' => 12],
        ]);
    }

    public function show(Project $project, Dashboard $dashboard): Response
    {
        Gate::authorize('view', $dashboard);

        return Inertia::render('Projects/Dashboards/Show', [
            'project' => self::project($project),
            'dashboard' => ['id' => $dashboard->uuid, 'name' => $dashboard->name],
            'links' => DashboardLinks::for($project),
            'urls' => ['runs' => DashboardLinks::runsBase($project)],
            'limits' => ['max_tiles' => Limits::tiles(), 'concurrent_runs' => 3],
        ]);
    }

    public function savedInsights(Project $project): Response
    {
        Gate::authorize('viewAny', [SavedInsight::class, $project]);

        return Inertia::render('Projects/SavedInsights/Index', [
            'project' => self::project($project),
            'links' => DashboardLinks::for($project),
            'limits' => ['max_saved_insights' => Limits::savedInsights(), 'page_size' => 20],
        ]);
    }

    /** @return array{id: int, name: string, timezone: string} */
    private static function project(Project $project): array
    {
        return ['id' => $project->id, 'name' => $project->name, 'timezone' => $project->timezone];
    }
}
