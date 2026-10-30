<?php

namespace App\Http;

use App\Domain\Insights\Runs\InsightRunStore;
use App\Domain\Insights\Runs\RunStart;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use Illuminate\Http\JsonResponse;

/**
 * The HTTP contract of asking for an insight run, shared by `POST insights/{type}` and `POST saved-insights/{id}/run`:
 *   200 {status: done, run_id: null, result}   a fresh cached result
 *   202 {status: queued, run_id, poll_url}     a run was queued (or the same live one again); Location and Retry-After too
 *   429 busy (a cap of the gate), 503 unavailable (store or queue down), both in the shape of {@see ApiErrors::body()}
 */
final class RunStartResponse
{
    private function __construct() {}

    public static function make(int $projectId, RunStart $start): JsonResponse
    {
        return match ($start->outcome) {
            RunStart::CACHED => response()->json(['status' => InsightRunStore::DONE, 'run_id' => null, 'result' => $start->result?->toArray()]),
            RunStart::QUEUED => self::queued($projectId, (string) $start->runId),
            RunStart::BUSY => self::busy((string) $start->message),
            default => self::unavailable(),
        };
    }

    public static function queued(int $projectId, string $runId): JsonResponse
    {
        $pollUrl = route('api.insights.runs.show', ['project' => $projectId, 'run' => $runId], absolute: false);

        return response()
            ->json(['status' => InsightRunStore::QUEUED, 'run_id' => $runId, 'poll_url' => $pollUrl], 202)
            ->header('Location', $pollUrl)
            ->header('Retry-After', (string) config('insights.poll_interval_seconds'));
    }

    public static function busy(string $message): JsonResponse
    {
        return InsightErrorResponse::forKind(429, ClickHouseErrorKind::Busy, $message);
    }

    public static function unavailable(): JsonResponse
    {
        return InsightErrorResponse::forKind(503, ClickHouseErrorKind::Unavailable, 'Insights are temporarily unavailable. Try again in a moment.');
    }
}
