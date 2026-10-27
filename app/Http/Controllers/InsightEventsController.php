<?php

namespace App\Http\Controllers;

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\Events\EventNamesBusy;
use App\Domain\Insights\InsightFailed;
use App\Http\InsightErrorResponse;
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
 * shape of {@see InsightErrorResponse}; messages are fixed sentences, never server text. The list is a convenience: the
 * picker also takes a name typed by hand, so a failure here never blocks a chart.
 */
class InsightEventsController extends Controller
{
    public function __invoke(EventNamesRequest $request, Project $project, EventNames $names): JsonResponse
    {
        try {
            $answer = $names->list($project->id, $request->search());
        } catch (EventNamesBusy $e) {
            return InsightErrorResponse::make(429, ClickHouseErrorKind::Busy->value, 'The event list is busy right now. Try again in a moment.', true, $e->retryAfterSeconds);
        } catch (InsightFailed $e) {
            return $this->failed($e);
        }

        return response()->json($answer);
    }

    private function failed(InsightFailed $e): JsonResponse
    {
        return match ($e->kind) {
            ClickHouseErrorKind::Busy => InsightErrorResponse::forKind(429, $e->kind, 'The analytics database is busy right now. Try again in a few seconds.'),
            ClickHouseErrorKind::Unavailable => InsightErrorResponse::forKind(503, $e->kind, 'The analytics database is not reachable right now. Try again in a moment.'),
            ClickHouseErrorKind::Timeout, ClickHouseErrorKind::MemoryLimit, ClickHouseErrorKind::TooManyRows => InsightErrorResponse::forKind(503, $e->kind, 'The event list could not be loaded in time. Type to narrow it down, or enter an event name by hand.'),
            default => InsightErrorResponse::forKind(500, $e->kind, 'The event list could not be loaded. Enter an event name by hand, or try again later.'),
        };
    }
}
