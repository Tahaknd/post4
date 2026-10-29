<?php

namespace App\Http\Controllers;

use App\Domain\Capture\FailureLimiter;
use App\Domain\Capture\FlagEvaluation;
use App\Domain\Capture\FlagEvaluator;
use App\Domain\Capture\FlagRequest;
use App\Domain\Capture\InvalidPayload;
use App\Domain\Capture\PayloadDecoder;
use App\Domain\Capture\ProjectResolver;
use App\Domain\Capture\SdkStubs;
use App\Domain\Capture\StubCallLimiter;
use App\Http\Controllers\Concerns\AnswersSdkRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The posthog-js endpoints that are not fully implemented yet: remote config (a stub, App\Domain\Capture\SdkStubs) and
 * feature flags (`/flags`, `/decide`: thin presenters over the FlagEvaluator, which answers "no flags" until
 * Milestone 7). The M7 plug-in point is documented on FlagEvaluator; the project id resolved here is its input.
 *
 * Like capture they run with no middleware at all (routes/capture.php): no session, cookie or CSRF. They are public, so
 * the token is checked against ProjectResolver (its cache first, a database read only for an unknown token) and a
 * failed check counts against the same per-ip FailureLimiter as capture. Their bodies are capped at
 * `capture.stub_max_body_bytes` and an ip may make `capture.stub_calls_per_minute` successful calls (StubCallLimiter).
 * They are not events: the per-project budget (ProjectRateLimiter) is never consumed.
 */
final class SdkStubController extends Controller
{
    use AnswersSdkRequests;

    /**
     * GET /array/{token}/config: the remote config as JSON. Cacheable for a minute by the browser only (`private`):
     * the answer is the same for every valid token, and it tells nothing but "this token exists", which the asker
     * already knows. Never stored by shared caches, so a deleted project is not served from a proxy.
     */
    public function config(Request $request, string $token, ProjectResolver $projects, FailureLimiter $failures, StubCallLimiter $calls): JsonResponse
    {
        $projectId = $this->admit($request, $token, $projects, $failures, $calls);
        if ($projectId instanceof JsonResponse) {
            return $projectId;
        }

        return response()->json(SdkStubs::remoteConfig($projectId, $token))
            ->header('Cache-Control', 'private, max-age=60')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /** GET /array/{token}/config.js: the same config as the script the SDK loads first. */
    public function configScript(Request $request, string $token, ProjectResolver $projects, FailureLimiter $failures, StubCallLimiter $calls): Response|JsonResponse
    {
        $projectId = $this->admit($request, $token, $projects, $failures, $calls);
        if ($projectId instanceof JsonResponse) {
            return $projectId;
        }

        return response(SdkStubs::remoteConfigScript($projectId, $token), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /flags (also /flags/): the answer of the current SDKs. */
    public function flags(Request $request, PayloadDecoder $decoder, ProjectResolver $projects, FailureLimiter $failures, StubCallLimiter $calls, FlagEvaluator $evaluator): JsonResponse
    {
        return $this->answerFlagRequest($request, $decoder, $projects, $failures, $calls, $evaluator, SdkStubs::flags(...));
    }

    /** POST /decide (also /decide/): the answer of SDKs that predate /flags. */
    public function decide(Request $request, PayloadDecoder $decoder, ProjectResolver $projects, FailureLimiter $failures, StubCallLimiter $calls, FlagEvaluator $evaluator): JsonResponse
    {
        return $this->answerFlagRequest($request, $decoder, $projects, $failures, $calls, $evaluator, SdkStubs::decide(...));
    }

    /**
     * @param  callable(FlagEvaluation): array<string, mixed>  $present
     */
    private function answerFlagRequest(Request $request, PayloadDecoder $decoder, ProjectResolver $projects, FailureLimiter $failures, StubCallLimiter $calls, FlagEvaluator $evaluator, callable $present): JsonResponse
    {
        $rejected = $this->guardBody($request, $failures, (int) config('capture.stub_max_body_bytes'))
            ?? $this->overCallBudget($request, $calls);
        if ($rejected !== null) {
            return $rejected;
        }

        try {
            $body = $decoder->decodeObject($request);
        } catch (InvalidPayload $e) {
            return $this->unauthenticated($failures, (string) $request->ip(), 400, 'invalid_payload', $e->getMessage());
        }

        // posthog-js sends `token`; server SDKs and old clients send `api_key`.
        $token = null;
        foreach (['token', 'api_key'] as $field) {
            if (is_string($body[$field] ?? null) && $body[$field] !== '') {
                $token = $body[$field];
                break;
            }
        }

        $projectId = $this->authenticate($request, $token, $projects, $failures);
        if ($projectId instanceof JsonResponse) {
            return $projectId;
        }
        $calls->hit((string) $request->ip());

        return response()->json($present($evaluator->evaluate(FlagRequest::fromBody($projectId, $body))))
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /** The GET endpoints: no body to decode, the same gates and the same token check. */
    private function admit(Request $request, string $token, ProjectResolver $projects, FailureLimiter $failures, StubCallLimiter $calls): int|JsonResponse
    {
        $rejected = $this->overCallBudget($request, $calls);
        if ($rejected !== null) {
            return $rejected;
        }

        $projectId = $this->authenticate($request, $token, $projects, $failures);
        if (! $projectId instanceof JsonResponse) {
            $calls->hit((string) $request->ip());
        }

        return $projectId;
    }

    private function overCallBudget(Request $request, StubCallLimiter $calls): ?JsonResponse
    {
        $retryAfter = $calls->exceededFor((string) $request->ip());

        return $retryAfter === null ? null : $this->unavailable('too_many_requests', 'Too many requests, retry later', $retryAfter, 429);
    }
}
