<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Capture\FailureLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The error answers shared by the endpoints that SDKs call without a session (capture and the SDK stubs): one fixed
 * JSON shape, `Retry-After` on the retryable ones, and the per-ip limiter for requests that did not authenticate.
 */
trait AnswersCaptureErrors
{
    /** Body size cap (config capture.max_body_bytes): the declared length first (cheap), then the real body. */
    private function bodyTooLarge(Request $request): bool
    {
        $maxBody = (int) config('capture.max_body_bytes');
        $declared = $request->header('Content-Length');

        return (is_string($declared) && ctype_digit($declared) && (int) $declared > $maxBody)
            || strlen($request->getContent()) > $maxBody;
    }

    /**
     * Answer for a request that did not authenticate: 429 when this ip has already used up its failure
     * budget (no further work), otherwise count the failure and answer with the specific error.
     */
    private function unauthenticated(FailureLimiter $failures, string $ip, int $status, string $code, string $detail): JsonResponse
    {
        $retryAfter = $failures->blockedFor($ip);
        if ($retryAfter !== null) {
            return $this->unavailable('too_many_requests', 'Too many failed attempts, retry later', $retryAfter, 429);
        }

        $failures->record($ip);

        return $this->error($status, $code, $detail);
    }

    /** A retryable error (503, or 429 for the per-ip limit) with its Retry-After. */
    private function unavailable(string $code, string $detail, int $retryAfter, int $status = 503): JsonResponse
    {
        return $this->error($status, $code, $detail)->header('Retry-After', (string) $retryAfter);
    }

    private function error(int $status, string $code, string $detail): JsonResponse
    {
        return response()->json(['type' => 'capture_error', 'code' => $code, 'detail' => $detail], $status);
    }
}
