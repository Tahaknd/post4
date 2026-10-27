<?php

namespace App\Http\Controllers;

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\Events\EventNamesBusy;
use App\Domain\Insights\InsightFailed;
use App\Http\ApiErrors;
use App\Http\Requests\Insights\EventNamesRequest;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/projects/{project}/insights/events[?q=text]`: the names for the event picker of the trends UI.
 *
 *   200 {events: [{name, count}], truncated}   the most frequent names of the last 30 UTC days (at most 100, count
 *                                              desc, then name), optionally those containing `q` (case-insensitive)
 *   422 validation (q longer than 100 characters or with control characters), 429 busy / throttled, 503 unavailable
 *
 * `{project}` is bound through Project::visibleTo(user): a project the user cannot open is a 404. Every error has the
 * shape of {@see ApiErrors::body()}; messages are fixed sentences, never server text. The list is a convenience: the
 * picker also takes a name typed by hand, so a failure here never blocks a chart.
 */
class InsightEventsController extends Controller
{
    public function __invoke(EventNamesRequest $request, Project $project, EventNames $names): JsonResponse
    {
        try {
            $answer = $names->list($project->id, $request->search());
        } catch (EventNamesBusy) {
            return $this->error(429, ClickHouseErrorKind::Busy, 'The event list is busy right now. Try again in a moment.', EventNamesBusy::RETRY_AFTER_SECONDS);
        } catch (InsightFailed $e) {
            return $this->failed($e);
        }

        return response()->json($answer);
    }

    private function failed(InsightFailed $e): JsonResponse
    {
        return match ($e->kind) {
            ClickHouseErrorKind::Busy => $this->error(429, $e->kind, 'The analytics database is busy right now. Try again in a few seconds.', $e->retryAfterSeconds()),
            ClickHouseErrorKind::Unavailable => $this->error(503, $e->kind, 'The analytics database is not reachable right now. Try again in a moment.', (int) config('insights.unavailable_retry_after_seconds')),
            ClickHouseErrorKind::Timeout, ClickHouseErrorKind::MemoryLimit, ClickHouseErrorKind::TooManyRows => $this->error(503, $e->kind, 'The event list could not be loaded in time. Type to narrow it down, or enter an event name by hand.', null),
            default => $this->error(500, $e->kind, 'The event list could not be loaded. Enter an event name by hand, or try again later.', null),
        };
    }

    private function error(int $status, ClickHouseErrorKind $kind, string $message, ?int $retryAfter): JsonResponse
    {
        $response = response()->json(ApiErrors::body($kind->value, $message, $retryAfter !== null || $kind->isRetryable(), $retryAfter), $status);

        return $retryAfter === null ? $response : $response->header('Retry-After', (string) $retryAfter);
    }
}
