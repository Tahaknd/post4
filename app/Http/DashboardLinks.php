<?php

namespace App\Http;

use App\Domain\Dashboards\Labels;
use App\Models\Project;

/**
 * The server-built addresses and limits the saved insight and dashboard UI needs, so the frontend never assembles a path
 * itself: the collection URLs of the JSON API (an item URL is one of them plus a uuid, built and checked by the client), the
 * pages, the three insight pages that "Open" leads to, and the base of the run URLs. Paths only, no keys, no host.
 */
final class DashboardLinks
{
    private function __construct() {}

    /**
     * @return array{
     *     api: array{saved_insights: string, dashboards: string},
     *     pages: array{saved_insights: string, dashboards: string, trends: string, funnel: string, retention: string, project: string},
     *     limits: array{name_max: int, description_max: int}
     * }
     */
    public static function for(Project $project): array
    {
        return [
            'api' => [
                'saved_insights' => route('api.saved-insights.index', $project, absolute: false),
                'dashboards' => route('api.dashboards.index', $project, absolute: false),
            ],
            'pages' => [
                'saved_insights' => route('projects.saved-insights.index', $project, absolute: false),
                'dashboards' => route('projects.dashboards.index', $project, absolute: false),
                'trends' => route('projects.insights.trends', $project, absolute: false),
                'funnel' => route('projects.insights.funnel', $project, absolute: false),
                'retention' => route('projects.insights.retention', $project, absolute: false),
                'project' => route('projects.show', $project, absolute: false),
            ],
            'limits' => ['name_max' => Labels::NAME_MAX_LENGTH, 'description_max' => Labels::DESCRIPTION_MAX_LENGTH],
        ];
    }

    /** `/api/projects/{id}/insights/runs/`, taken from the route itself (a run id placeholder is cut off again). */
    public static function runsBase(Project $project): string
    {
        $placeholder = str_repeat('0', 32);

        return str_replace($placeholder, '', route('api.insights.runs.show', ['project' => $project, 'run' => $placeholder], absolute: false));
    }
}
