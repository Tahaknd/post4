<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Capture\FailureLimiter;
use App\Domain\Capture\ProjectResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * What the endpoints that SDKs call without a session (capture and the SDK stubs) share: the body size gate, the
 * project token check, one fixed JSON error shape with `Retry-After` on the retryable ones, and the per-ip limiter for
 * requests that did not authenticate.
 */
trait AnswersSdkRequests
{
    /**
     * Gate on the body size, run BEFORE anything decodes it.
     *
     * A body above `capture.blocked_decode_max_bytes` from an ip that has used up its failure budget is answered with a
     * bare 429: the decode (gunzip, container scan, json_decode) is the expensive part of a request, and an ip that is
     * already blocked must not be able to buy it. Smaller bodies still get through the decode, so a valid key behind
     * a shared address (NAT, office) keeps working while a neighbour retries a rotated key; a legitimate large batch
     * from a blocked ip is delayed by Retry-After, which SDKs honour. Above `$cap` the answer is 413.
     */
    private function guardBody(Request $request, FailureLimiter $failures, int $cap): ?JsonResponse
    {
        $declared = $request->header('Content-Length');
        $size = max(is_string($declared) && ctype_digit($declared) ? (int) $declared : 0, strlen($request->getContent()));

        if ($size > (int) config('capture.blocked_decode_max_bytes')) {
            $retryAfter = $failures->blockedFor((string) $request->ip());
            if ($retryAfter !== null) {
                return $this->unavailable('too_many_requests', 'Too many failed attempts, retry later', $retryAfter, 429);
            }
        }

        return $size > $cap ? $this->error(413, 'payload_too_large', 'Request body is too large') : null;
    }

    /**
     * The project the token belongs to, or the answer to give: 503 when the lookup itself failed, otherwise 401
     * (counted against the ip) or 429 when the ip is already blocked.
     */
    private function authenticate(Request $request, ?string $token, ProjectResolver $projects, FailureLimiter $failures): int|JsonResponse
    {
        try {
            $projectId = $token !== null ? $projects->resolve($token) : null;
        } catch (\Throwable $e) {
            Log::error('sdk project lookup failed', ['exception' => $e::class, 'controller' => class_basename($this)]);

            return $this->unavailable('service_unavailable', 'Project lookup is unavailable, retry later', 5);
        }

        return $projectId ?? $this->unauthenticated($failures, (string) $request->ip(), 401, 'invalid_api_key', 'API key is missing or invalid');
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

    /** A retryable error (503, or 429 for a per-ip limit) with its Retry-After. */
    private function unavailable(string $code, string $detail, int $retryAfter, int $status = 503): JsonResponse
    {
        return $this->error($status, $code, $detail)->header('Retry-After', (string) $retryAfter);
    }

    private function error(int $status, string $code, string $detail): JsonResponse
    {
        return response()->json(['type' => 'capture_error', 'code' => $code, 'detail' => $detail], $status);
    }
}
