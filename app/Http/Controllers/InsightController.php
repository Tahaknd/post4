<?php

namespace App\Http\Controllers;

use App\Domain\Insights\InsightQuery;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\Runs\InsightGate;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Http\ApiErrors;
use App\Http\InsightErrorResponse;
use App\Http\Requests\Insights\TrendsInsightRequest;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Jobs\RunInsight;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The insight JSON API. `{project}` is bound through Project::visibleTo(user) (AppServiceProvider): a project the
 * user cannot open is a 404, exactly like one that does not exist, and the project id is never read from a body.
 *
 *   POST   /api/projects/{project}/insights/{type}      one route per registered type (App\Domain\Insights\InsightTypes),
 *                                                       each with the request class of its type; the flow is the same:
 *                                                       200 {status: done, run_id: null, result}  a fresh cached result
 *                                                       202 {status: queued, run_id, poll_url}    a run was queued, or
 *                                                           the SAME run again when an identical POST of this user is
 *                                                           still queued or running (idempotent)
 *                                                       422 validation, 429 busy / throttled, 503 unavailable
 *   GET    /api/projects/{project}/insights/runs/{run}  200 {status: queued|running|done|failed|cancelled, ...}
 *   DELETE /api/projects/{project}/insights/runs/{run}  204 cancels a queued or running run (idempotent), frees its slots
 *
 * A heavy insight never runs inside the request: it is a RunInsight job (one class per type) on the `insights` queue, and there is
 * no synchronous mode. The only failures that are HTTP errors are the ones that happen BEFORE a run exists (caps,
 * throttle, store down); everything that goes wrong while the query runs is a 200 poll answer with status `failed`
 * and an `error` object. Every error body has the shape of {@see ApiErrors::body()}: `kind` is a ClickHouseErrorKind
 * value (or a transport kind), `message` a fixed user-safe sentence, never server text. A run that is not found any
 * more (404) has EXPIRED (or was never yours): submit again.
 *
 * Fail-closed on a dead store: a run needs the store to be queued and to deliver its result, so with Redis down the
 * POST answers 503 `unavailable` instead of promising something it cannot deliver (the limiters elsewhere fail open
 * because they only protect; this store IS the delivery channel).
 */
class InsightController extends Controller
{
    public function trends(TrendsInsightRequest $request, Project $project, InsightRunner $runner, InsightRunStore $runs, InsightGate $gate): JsonResponse
    {
        return $this->start($request, $project, $request->definition(), $runner, $runs, $gate);
    }

    /**
     * The whole flow of starting a run, the same for every insight type: the definition is already validated and built
     * by the type's request class. 200 with the cached result, else (limits permitting) 202 with a run id.
     */
    private function start(Request $request, Project $project, InsightQuery $query, InsightRunner $runner, InsightRunStore $runs, InsightGate $gate): JsonResponse
    {
        $user = $this->user($request);
        $hash = $query->hash();

        // Identical definitions of the same local day share this entry (see TrendsInsightRequest on quantisation).
        $cached = $runner->cached($project->id, $query);
        if ($cached !== null) {
            return response()->json(['status' => InsightRunStore::DONE, 'run_id' => null, 'result' => $cached->toArray()]);
        }

        $userId = (int) $user->getKey();
        $runId = InsightRunStore::newId();
        $projectSlot = null;
        $userSlot = null;
        $claimed = false;
        $created = false;

        try {
            // Idempotent: a live run of this user for this definition is answered again, nothing new is queued.
            $existing = $this->liveRun($runs, $gate, $project->id, $userId, $hash);
            if ($existing !== null) {
                return $this->queuedResponse($project->id, $existing);
            }

            $claimed = $gate->claimInflight($project->id, $userId, $hash, $runId);
            if (! $claimed) {
                // Lost a race with an identical request that is just being queued.
                $winner = $gate->inflightRunId($project->id, $userId, $hash);

                return $winner !== null ? $this->queuedResponse($project->id, $winner) : $this->busy('Too many insights of this project are running. Try again in a few seconds.');
            }

            $userSlot = $gate->acquireUserSlot($userId, $runId);
            if ($userSlot === null) {
                $this->discard($runs, $gate, $project->id, $userId, $runId, $hash, $projectSlot, $userSlot, created: false);

                return $this->busy('You have too many insights running. Wait for one to finish, or cancel one, then try again.');
            }

            $projectSlot = $gate->acquireProjectSlot($project->id, $runId);
            if ($projectSlot === null) {
                $this->discard($runs, $gate, $project->id, $userId, $runId, $hash, $projectSlot, $userSlot, created: false);

                return $this->busy('Too many insights of this project are running. Try again in a few seconds.');
            }

            $runs->create($runId, $query->type(), $project->id, $userId, $hash, $projectSlot, $userSlot);
            $created = true;
            RunInsight::dispatchFor($query, $project->id, $runId, $userId, $projectSlot, $userSlot);
        } catch (Throwable $e) {
            // Ids and the exception class only: the message of a Redis or queue failure is not for the log of every request.
            Log::warning('insight run could not be queued', ['project_id' => $project->id, 'exception' => $e::class]);
            $this->discard($runs, $gate, $project->id, $userId, $runId, $hash, $projectSlot, $userSlot, $created, $claimed);

            return $this->unavailable();
        }

        return $this->queuedResponse($project->id, $runId);
    }

    public function show(Request $request, Project $project, string $run, InsightRunStore $runs): JsonResponse
    {
        $record = $this->ownedRecord($request, $project, $run, $runs);
        if ($record instanceof JsonResponse) {
            return $record;
        }

        return match ($record['status']) {
            InsightRunStore::DONE => response()->json(['status' => 'done', 'run_id' => $run, 'result' => $record['result']]),
            InsightRunStore::FAILED => response()->json(['status' => 'failed', 'run_id' => $run, 'error' => InsightErrorResponse::payload(ClickHouseErrorKind::tryFrom((string) $record['error']) ?? ClickHouseErrorKind::Unknown)]),
            InsightRunStore::CANCELLED => response()->json(['status' => 'cancelled', 'run_id' => $run]),
            default => response()->json(['status' => $record['status'], 'run_id' => $run])->header('Retry-After', (string) config('insights.poll_interval_seconds')),
        };
    }

    /**
     * Cancels a queued or running run. Same authorisation as show() (unknown, foreign and expired runs are the same
     * 404), idempotent (an already finished or cancelled run answers 204 as well). The job notices the cancellation
     * before it queries; a query that already runs is not interrupted, its answer is discarded.
     */
    public function cancel(Request $request, Project $project, string $run, InsightRunStore $runs, InsightGate $gate): JsonResponse|Response
    {
        $record = $this->ownedRecord($request, $project, $run, $runs);
        if ($record instanceof JsonResponse) {
            return $record;
        }

        try {
            if (! InsightRunStore::isTerminal($record['status'])) {
                $runs->cancel($run);
            }
            // The job frees them too (from its payload); owner-checked, so freeing twice is harmless and a cancelled
            // run that is still waiting in the queue does not hold its capacity until the job gets to it.
            $gate->releaseSlots($project->id, $record['user_id'], $run, $record['project_slot'], $record['user_slot']);
            $gate->releaseInflight($project->id, $record['user_id'], $record['hash'], $run);
        } catch (Throwable $e) {
            Log::warning('insight run could not be cancelled', ['project_id' => $project->id, 'exception' => $e::class]);

            return $this->unavailable();
        }

        return response()->noContent();
    }

    /**
     * The record of `$run` when this user may see it, else a JSON answer: 503 when the store is down, and ONE 404 for
     * an unknown run, a run of another project and a run of another user (guessing tells nothing). An administrator
     * may read every run of a project they can open.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function ownedRecord(Request $request, Project $project, string $run, InsightRunStore $runs): array|JsonResponse
    {
        $user = $this->user($request);
        Gate::authorize('view', $project);

        try {
            $record = $runs->find($run);
        } catch (Throwable $e) {
            Log::warning('insight run could not be read', ['project_id' => $project->id, 'exception' => $e::class]);

            return $this->unavailable();
        }

        abort_if(
            $record === null || $record['project_id'] !== $project->id || ($record['user_id'] !== $user->getKey() && $user->is_admin !== true),
            404,
        );

        return $record;
    }

    /** The id of a queued or running run this user already has for the definition, else null. */
    private function liveRun(InsightRunStore $runs, InsightGate $gate, int $projectId, int $userId, string $hash): ?string
    {
        $existing = $gate->inflightRunId($projectId, $userId, $hash);
        if ($existing === null) {
            return null;
        }

        $record = $runs->find($existing);
        if ($record !== null && $record['project_id'] === $projectId && $record['user_id'] === $userId
            && in_array($record['status'], [InsightRunStore::QUEUED, InsightRunStore::RUNNING], true)) {
            return $existing;
        }

        // A key that outlived its run (evicted record, finished without releasing): free it and carry on.
        $gate->releaseInflight($projectId, $userId, $hash, $existing);

        return null;
    }

    private function queuedResponse(int $projectId, string $runId): JsonResponse
    {
        $pollUrl = route('api.insights.runs.show', ['project' => $projectId, 'run' => $runId], absolute: false);

        return response()
            ->json(['status' => InsightRunStore::QUEUED, 'run_id' => $runId, 'poll_url' => $pollUrl], 202)
            ->header('Location', $pollUrl)
            ->header('Retry-After', (string) config('insights.poll_interval_seconds'));
    }

    private function busy(string $message): JsonResponse
    {
        return InsightErrorResponse::forKind(429, ClickHouseErrorKind::Busy, $message);
    }

    private function unavailable(): JsonResponse
    {
        return InsightErrorResponse::forKind(503, ClickHouseErrorKind::Unavailable, 'Insights are temporarily unavailable. Try again in a moment.');
    }

    /**
     * Best effort: everything a half-queued run holds is released now, the TTLs clean up anything that fails.
     * Nothing is touched that was never taken.
     */
    private function discard(InsightRunStore $runs, InsightGate $gate, int $projectId, int $userId, string $runId, string $hash, ?int $projectSlot, ?int $userSlot, bool $created, bool $claimed = true): void
    {
        if ($created) {
            try {
                $runs->forget($runId);
            } catch (Throwable) {
                // expires by itself
            }
        }
        $gate->releaseSlots($projectId, $userId, $runId, $projectSlot, $userSlot);
        if ($claimed) {
            $gate->releaseInflight($projectId, $userId, $hash, $runId);
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
