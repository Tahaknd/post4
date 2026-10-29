<?php

namespace App\Http\Controllers;

use App\Domain\Capture\FailureLimiter;
use App\Domain\Capture\InvalidPayload;
use App\Domain\Capture\PayloadDecoder;
use App\Domain\Capture\ProjectResolver;
use App\Domain\Capture\SdkStubs;
use App\Http\Controllers\Concerns\AnswersCaptureErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * STUBS for the posthog-js endpoints that are not implemented yet: remote config and feature flags (answers and the
 * verified SDK behaviour: App\Domain\Capture\SdkStubs). Milestone 7 replaces `flags`/`decide` with real flag
 * evaluation; the project id they resolve here is the input it needs.
 *
 * Like capture they run with no middleware at all (routes/capture.php): no session, no cookie, no CSRF. They are
 * public, so the token is checked against ProjectResolver (its cache first, a database read only for an unknown
 * token) and a failed check counts against the same per-ip FailureLimiter as capture. They are not events: the
 * per-project budget (ProjectRateLimiter) is never consumed.
 */
final class SdkStubController extends Controller
{
    use AnswersCaptureErrors;

    /**
     * GET /array/{token}/config: the remote config as JSON. Cacheable for a minute by the browser only (`private`):
     * the answer is the same for every valid token, and it tells nothing but "this token exists", which the asker
     * already knows. Never stored by shared caches, so a deleted project is not served from a proxy.
     */
    public function config(Request $request, string $token, ProjectResolver $projects, FailureLimiter $failures): JsonResponse
    {
        $failure = $this->rejectUnknownToken($request, $token, $projects, $failures);
        if ($failure !== null) {
            return $failure;
        }

        return response()->json(SdkStubs::remoteConfig($token))
            ->header('Cache-Control', 'private, max-age=60')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /** GET /array/{token}/config.js: the same config as the script the SDK loads first. */
    public function configScript(Request $request, string $token, ProjectResolver $projects, FailureLimiter $failures): Response|JsonResponse
    {
        $failure = $this->rejectUnknownToken($request, $token, $projects, $failures);
        if ($failure !== null) {
            return $failure;
        }

        return response(SdkStubs::remoteConfigScript($token), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /flags (also /flags/): the answer of the current SDKs, no flag exists. */
    public function flags(Request $request, PayloadDecoder $decoder, ProjectResolver $projects, FailureLimiter $failures): JsonResponse
    {
        return $this->answerFlagRequest($request, $decoder, $projects, $failures, SdkStubs::flags(...));
    }

    /** POST /decide (also /decide/): the answer of SDKs that predate /flags, no flag exists. */
    public function decide(Request $request, PayloadDecoder $decoder, ProjectResolver $projects, FailureLimiter $failures): JsonResponse
    {
        return $this->answerFlagRequest($request, $decoder, $projects, $failures, SdkStubs::decide(...));
    }

    /**
     * @param  callable(): array<string, mixed>  $answer
     */
    private function answerFlagRequest(Request $request, PayloadDecoder $decoder, ProjectResolver $projects, FailureLimiter $failures, callable $answer): JsonResponse
    {
        // The ip is the trusted proxy aware one (`$request->ip()`), never a raw X-Forwarded-For.
        $clientIp = (string) $request->ip();

        if ($this->bodyTooLarge($request)) {
            return $this->error(413, 'payload_too_large', 'Request body is too large');
        }

        try {
            $body = $decoder->decodeObject($request);
        } catch (InvalidPayload $e) {
            return $this->unauthenticated($failures, $clientIp, 400, 'invalid_payload', $e->getMessage());
        }

        // posthog-js sends `token`; server SDKs and old clients send `api_key`.
        $token = null;
        foreach (['token', 'api_key'] as $field) {
            if (is_string($body[$field] ?? null) && $body[$field] !== '') {
                $token = $body[$field];
                break;
            }
        }

        $failure = $this->rejectUnknownToken($request, $token, $projects, $failures);
        if ($failure !== null) {
            return $failure;
        }

        return response()->json($answer())
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Null when the token belongs to a project, otherwise the answer to give: 401 (counted against the ip), 429 when
     * the ip is blocked, 503 when the lookup itself failed.
     */
    private function rejectUnknownToken(Request $request, ?string $token, ProjectResolver $projects, FailureLimiter $failures): ?JsonResponse
    {
        try {
            $projectId = $token !== null ? $projects->resolve($token) : null;
        } catch (\Throwable $e) {
            Log::error('sdk stub project lookup failed', ['exception' => $e::class]);

            return $this->unavailable('service_unavailable', 'Project lookup is unavailable, retry later', 5);
        }

        if ($projectId === null) {
            return $this->unauthenticated($failures, (string) $request->ip(), 401, 'invalid_api_key', 'API key is missing or invalid');
        }

        return null;
    }
}
