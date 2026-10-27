<?php

namespace App\Http;

use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use Illuminate\Http\JsonResponse;

/**
 * The one place that turns "this insight request failed" into the API's error shape ({@see ApiErrors::body()}): a
 * `kind` (a ClickHouseErrorKind value or a transport kind such as `busy`), a FIXED user-safe `message` (never server
 * text), `retryable` and the seconds to wait, which also become the `Retry-After` header.
 */
final class InsightErrorResponse
{
    private function __construct() {}

    public static function make(int $status, string $kind, string $message, bool $retryable, ?int $retryAfterSeconds = null): JsonResponse
    {
        $response = response()->json(ApiErrors::body($kind, $message, $retryable, $retryAfterSeconds), $status);

        return $retryAfterSeconds === null ? $response : $response->header('Retry-After', (string) $retryAfterSeconds);
    }

    /** For a ClickHouse kind: `retryable` and the wait come from the kind (and the configured wait for `unavailable`). */
    public static function forKind(int $status, ClickHouseErrorKind $kind, ?string $message = null, ?int $retryAfterSeconds = null): JsonResponse
    {
        $error = self::payload($kind, $message, $retryAfterSeconds);

        return self::make($status, $error['kind'], $error['message'], $error['retryable'], $error['retry_after_seconds']);
    }

    /**
     * The `error` object of a kind (also what a failed run answers on a poll).
     *
     * @return array{kind: string, message: string, retryable: bool, retry_after_seconds: ?int}
     */
    public static function payload(ClickHouseErrorKind $kind, ?string $message = null, ?int $retryAfterSeconds = null): array
    {
        return [
            'kind' => $kind->value,
            'message' => $message ?? $kind->userMessage(),
            'retryable' => $kind->isRetryable(),
            'retry_after_seconds' => $retryAfterSeconds ?? self::retryAfterFor($kind),
        ];
    }

    /** Busy has its own wait (ClickHouseErrorKind), the other retryable kind (unavailable) the configured one, the rest none. */
    public static function retryAfterFor(ClickHouseErrorKind $kind): ?int
    {
        return $kind->retryAfterSeconds() ?? ($kind->isRetryable() ? (int) config('insights.unavailable_retry_after_seconds') : null);
    }
}
