<?php

namespace App\Http\Controllers;

use App\Actions\SavedInsights\CreateSavedInsight;
use App\Actions\SavedInsights\DeleteSavedInsight;
use App\Actions\SavedInsights\UpdateSavedInsight;
use App\Domain\Dashboards\SavedQueryResolver;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightRunStarter;
use App\Domain\Insights\Runs\RunStart;
use App\Domain\Insights\Runs\StartOptions;
use App\Http\ApiErrors;
use App\Http\Requests\Dashboards\ListRequest;
use App\Http\Requests\SavedInsights\RunSavedInsightRequest;
use App\Http\Requests\SavedInsights\StoreSavedInsightRequest;
use App\Http\Requests\SavedInsights\UpdateSavedInsightRequest;
use App\Http\Resources\SavedInsightResource;
use App\Http\RunStartResponse;
use App\Models\Project;
use App\Models\SavedInsight;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The saved insight JSON API. `{project}` and `{insight}` are bound by AppServiceProvider (an insight of another project is
 * a 404, exactly like a missing one; the URL carries the uuid), and every action is authorised by SavedInsightPolicy.
 * Writes are one call to an action each; this class only turns requests into those calls and results into JSON.
 *
 *   GET    /api/projects/{project}/saved-insights                  list (page, per_page, type), newest change first
 *   POST   /api/projects/{project}/saved-insights                  201 the new insight
 *   GET    /api/projects/{project}/saved-insights/{insight}        the insight with its stored query and its state
 *   PATCH  /api/projects/{project}/saved-insights/{insight}        name, description and/or a new query of its own type
 *   DELETE /api/projects/{project}/saved-insights/{insight}        204, also from every dashboard
 *   POST   /api/projects/{project}/saved-insights/{insight}/run    the insight run contract, see run()
 */
class SavedInsightController extends Controller
{
    public function index(ListRequest $request, Project $project, SavedQueryResolver $resolver): JsonResponse
    {
        Gate::authorize('viewAny', [SavedInsight::class, $project]);

        $page = SavedInsight::query()
            ->where('project_id', $project->getKey())
            ->when($request->type() !== null, fn ($query) => $query->where('type', $request->type()))
            ->with('creator:id,name')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        $now = now();

        return response()->json([
            'data' => $page->getCollection()->map(fn (SavedInsight $insight) => SavedInsightResource::summary($insight, $resolver, $now))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(StoreSavedInsightRequest $request, Project $project, CreateSavedInsight $create, SavedQueryResolver $resolver): JsonResponse
    {
        $insight = $create->handle(
            $project,
            (string) $request->validated('name'),
            $request->validated('description'),
            (string) $request->validated('type'),
            $request->queryInput(),
            $this->user($request),
        );

        return response()->json(['data' => SavedInsightResource::detail($insight->load('creator:id,name'), $resolver, now())], 201);
    }

    public function show(Request $request, Project $project, SavedInsight $insight, SavedQueryResolver $resolver): JsonResponse
    {
        Gate::authorize('view', $insight);

        return response()->json(['data' => SavedInsightResource::detail($insight->load('creator:id,name'), $resolver, now())]);
    }

    public function update(UpdateSavedInsightRequest $request, Project $project, SavedInsight $insight, UpdateSavedInsight $update, SavedQueryResolver $resolver): JsonResponse
    {
        $updated = $update->handle($insight, $request->changes(), $this->user($request));

        return response()->json(['data' => SavedInsightResource::detail($updated->load('creator:id,name'), $resolver, now())]);
    }

    public function destroy(Request $request, Project $project, SavedInsight $insight, DeleteSavedInsight $delete): Response
    {
        Gate::authorize('delete', $insight);

        $delete->handle($insight, $this->user($request));

        return response()->noContent();
    }

    /**
     * Runs the saved definition exactly like `POST insights/{type}` runs the same canonical query (the same starter, the
     * same caps, the same polling endpoints, no new execution path), with the stored query read through the state policy:
     *
     *   422 needs_review      the stored definition cannot run NOW (`error.reason`: unknown_type, outdated_version,
     *                         invalid_definition, invalid_date). It is never run, never refreshed.
     *   200 {status: done, run_id: null, result, source, computed_at}
     *                         a stored result: `source` is `dashboard_cache` (kept by the scheduled refresh and by earlier
     *                         runs of this endpoint; `computed_at` is when it was computed) or `cache` (the 60 s interactive
     *                         cache, `computed_at` null)
     *   202 {status: queued, run_id, poll_url}   a run was queued: poll it at the usual endpoint; its result also goes to
     *                         the dashboard cache for the next viewer
     *   429 / 503             as for every insight run
     * A relative range is resolved to today on every call. `{"fresh": true}` skips the dashboard cache.
     */
    public function run(RunSavedInsightRequest $request, Project $project, SavedInsight $insight, SavedQueryResolver $resolver, InsightRunner $runner, InsightRunStarter $starter): JsonResponse
    {
        $state = $resolver->resolve($insight, now());
        if ($state->query === null) {
            return response()->json(self::needsReview((string) $state->reason), 422);
        }

        if (! $request->fresh()) {
            $stored = $runner->cachedForDashboard($project->id, $state->query);
            if ($stored !== null) {
                return response()->json([
                    'status' => 'done',
                    'run_id' => null,
                    'result' => $stored->result->toArray(),
                    'source' => 'dashboard_cache',
                    'computed_at' => now()->setTimestamp($stored->computedAt)->toIso8601String(),
                ]);
            }
        }

        $start = $starter->start($project->id, (int) $this->user($request)->getKey(), $state->query, new StartOptions(forDashboard: true));
        $response = RunStartResponse::make($project->id, $start);

        if ($start->outcome === RunStart::CACHED) {
            $response->setData([...(array) $response->getData(true), 'source' => 'cache', 'computed_at' => null]);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private static function needsReview(string $reason): array
    {
        $body = ApiErrors::body('needs_review', 'This saved insight cannot run as it is stored. Open it, check it and save it again.');
        $body['error']['reason'] = $reason;

        return $body;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
