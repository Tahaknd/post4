<?php

namespace App\Http\Controllers;

use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Http\Requests\Insights\TrendsInsightRequest;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Jobs\RunTrendsInsight;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The insight JSON API. `{project}` is bound through Project::visibleTo(user) (AppServiceProvider): a project the
 * user cannot open is a 404, exactly like one that does not exist, and the project id is never read from a body.
 *
 *   POST /api/projects/{project}/insights/trends   200 {status: done, result}            a fresh cached result
 *                                                  202 {status: queued, run_id, poll_url} a run was queued
 *                                                  422 validation, 429 busy / throttled, 503 unavailable
 *   GET  /api/projects/{project}/insights/runs/{run}   200 {status: queued|running|done|failed, ...}
 *
 * A heavy insight never runs inside the request: it is a RunTrendsInsight job on the `insights` queue, and there is
 * no synchronous mode. The only failures that are HTTP errors are the ones that happen BEFORE a run exists (cap,
 * throttle, store down); everything that goes wrong while the query runs is a 200 poll answer with status `failed`
 * and an `error` object. The error object is always {kind, message, retryable, retry_after_seconds}: `kind` is a
 * ClickHouseErrorKind value, `message` its fixed user-safe sentence, never server text.
 *
 * Fail-closed on a dead store: a run needs the store to be queued and to deliver its result, so with Redis down the
 * POST answers 503 `unavailable` instead of promising something it cannot deliver (the limiters elsewhere fail open
 * because they only protect; this store IS the delivery channel).
 */
class InsightController extends Controller
{
    private const int UNAVAILABLE_RETRY_AFTER_SECONDS = 5;

    public function trends(TrendsInsightRequest $request, Project $project, InsightRunner $runner, InsightRunStore $runs, InsightGate $gate): JsonResponse
    {
        $user = $this->user($request);
        $query = $request->definition();

        // Identical definitions of the same local day share this entry (see TrendsInsightRequest on quantisation).
        $cached = $runner->cached($project->id, $query);
        if ($cached !== null) {
            return response()->json(['status' => InsightRunStore::DONE, 'result' => $cached->toArray()]);
        }

        $runId = InsightRunStore::newId();
        $slot = null;

        try {
            $slot = $gate->acquireDispatchSlot($project->id, $runId);
            if ($slot === null) {
                return $this->errorResponse(429, ClickHouseErrorKind::Busy, 'Too many insights of this project are running. Try again in a few seconds.');
            }

            $runs->create($runId, $project->id, (int) $user->getKey(), $query->hash(), $slot);
            RunTrendsInsight::dispatch($project->id, $runId, $query->canonical());
        } catch (Throwable $e) {
            // Ids and the exception class only: the message of a Redis or queue failure is not for the log of every request.
            Log::warning('insight run could not be queued', ['project_id' => $project->id, 'exception' => $e::class]);
            $this->discard($runs, $gate, $project->id, $runId, $slot);

            return $this->errorResponse(503, ClickHouseErrorKind::Unavailable, 'Insights are temporarily unavailable. Try again in a moment.', self::UNAVAILABLE_RETRY_AFTER_SECONDS);
        }

        $pollUrl = route('api.insights.runs.show', ['project' => $project->id, 'run' => $runId], absolute: false);

        return response()
            ->json(['status' => InsightRunStore::QUEUED, 'run_id' => $runId, 'poll_url' => $pollUrl], 202)
            ->header('Location', $pollUrl)
            ->header('Retry-After', '1');
    }

    public function show(Request $request, Project $project, string $run, InsightRunStore $runs): JsonResponse
    {
        $user = $this->user($request);
        Gate::authorize('view', $project);

        try {
            $record = $runs->find($run);
        } catch (Throwable $e) {
            Log::warning('insight run could not be read', ['project_id' => $project->id, 'exception' => $e::class]);

            return $this->errorResponse(503, ClickHouseErrorKind::Unavailable, 'Insights are temporarily unavailable. Try again in a moment.', self::UNAVAILABLE_RETRY_AFTER_SECONDS);
        }

        // One 404 for an unknown run, a run of another project and a run of another user: guessing tells nothing.
        // An administrator may read every run of a project they can open.
        abort_if(
            $record === null || $record['project_id'] !== $project->id || ($record['user_id'] !== $user->getKey() && $user->is_admin !== true),
            404,
        );

        return match ($record['status']) {
            InsightRunStore::DONE => response()->json(['status' => 'done', 'run_id' => $run, 'result' => $record['result']]),
            InsightRunStore::FAILED => $this->failedResponse($run, ClickHouseErrorKind::tryFrom((string) $record['error']) ?? ClickHouseErrorKind::Unknown),
            default => response()->json(['status' => $record['status'], 'run_id' => $run])->header('Retry-After', '1'),
        };
    }

    private function failedResponse(string $runId, ClickHouseErrorKind $kind): JsonResponse
    {
        return response()->json(['status' => 'failed', 'run_id' => $runId, 'error' => $this->error($kind)]);
    }

    private function errorResponse(int $status, ClickHouseErrorKind $kind, string $message, ?int $retryAfter = null): JsonResponse
    {
        $error = $this->error($kind, $message, $retryAfter);
        $response = response()->json(['message' => $error['message'], 'error' => $error], $status);

        return $error['retry_after_seconds'] === null ? $response : $response->header('Retry-After', (string) $error['retry_after_seconds']);
    }

    /** @return array{kind: string, message: string, retryable: bool, retry_after_seconds: ?int} */
    private function error(ClickHouseErrorKind $kind, ?string $message = null, ?int $retryAfter = null): array
    {
        return [
            'kind' => $kind->value,
            'message' => $message ?? $kind->userMessage(),
            'retryable' => $kind->isRetryable(),
            'retry_after_seconds' => $retryAfter ?? $kind->retryAfterSeconds() ?? ($kind->isRetryable() ? self::UNAVAILABLE_RETRY_AFTER_SECONDS : null),
        ];
    }

    /** Best effort: what a half-queued run holds is released now, the TTLs clean up anything that fails. */
    private function discard(InsightRunStore $runs, InsightGate $gate, int $projectId, string $runId, ?int $slot): void
    {
        try {
            $runs->forget($runId);
        } catch (Throwable) {
            // expires by itself
        }
        $gate->releaseDispatchSlot($projectId, $runId, $slot);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
