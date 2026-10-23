<?php

namespace App\Http\Controllers;

use App\Domain\Capture\EventNormalizer;
use App\Domain\Capture\EventProducer;
use App\Domain\Capture\FailureLimiter;
use App\Domain\Capture\InvalidPayload;
use App\Domain\Capture\PayloadDecoder;
use App\Domain\Capture\ProducerUnavailable;
use App\Domain\Capture\ProjectRateLimiter;
use App\Domain\Capture\ProjectResolver;
use App\Domain\Capture\TooManyEvents;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class CaptureController extends Controller
{
    /** Strict ISO-8601 / SQL-ish timestamp; anything else (e.g. "tomorrow") is ignored. */
    private const SENT_AT_FORMAT = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})?$/';

    public function __invoke(
        Request $request,
        PayloadDecoder $decoder,
        ProjectResolver $projects,
        EventNormalizer $normalizer,
        EventProducer $producer,
        FailureLimiter $failures,
        ProjectRateLimiter $projectLimiter,
    ): JsonResponse {
        // 1. Body size: the declared length first (cheap), then the real body.
        $maxBody = (int) config('capture.max_body_bytes');
        $declared = $request->header('Content-Length');
        if ((is_string($declared) && ctype_digit($declared) && (int) $declared > $maxBody)
            || strlen($request->getContent()) > $maxBody) {
            return $this->error(413, 'payload_too_large', 'Request body is too large');
        }

        // Per-IP limiter for UNAUTHENTICATED outcomes only; the policy is in FailureLimiter's docblock.
        $clientIp = (string) $request->ip();

        // 2. Decode.
        try {
            $payload = $decoder->decode($request);
        } catch (TooManyEvents) {
            return $this->unauthenticated($failures, $clientIp, 413, 'batch_too_large', 'Too many events in one batch');
        } catch (InvalidPayload $e) {
            return $this->unauthenticated($failures, $clientIp, 400, 'invalid_payload', $e->getMessage());
        }

        // 3. Nothing to do.
        if ($payload->events === []) {
            return $this->unauthenticated($failures, $clientIp, 400, 'no_events', 'No events in payload');
        }

        // 4. Authenticate before normalizing or producing anything.
        try {
            $projectId = $payload->apiKey !== null ? $projects->resolve($payload->apiKey) : null;
        } catch (\Throwable $e) {
            Log::error('capture project lookup failed', ['exception' => $e::class]);

            return $this->unavailable('service_unavailable', 'Project lookup is unavailable, retry later', 5);
        }
        if ($projectId === null) {
            return $this->unauthenticated($failures, $clientIp, 401, 'invalid_api_key', 'API key is missing or invalid');
        }

        // 5. Reserve the project's event budget before any normalising work, so a spent project costs
        // only the decode. Whatever is not produced below is released again.
        $now = CarbonImmutable::now('UTC');
        $nowTs = $now->getTimestamp();
        $reserved = count($payload->events);
        $limited = $projectLimiter->reserve($projectId, $reserved, $nowTs);
        if ($limited !== null) {
            return $this->unavailable('rate_limited', 'Project event rate limit exceeded, retry later', $limited);
        }

        $produced = 0;
        try {
            $sentAt = $this->parseSentAt($payload->sentAt);
            $ip = $this->ipDisabled($request->query('ip')) ? null : $request->ip();

            $messages = [];
            $months = [];
            foreach ($payload->events as $raw) {
                $message = $normalizer->normalize($raw, $projectId, $now, $sentAt, $ip);
                if ($message !== null) {
                    $messages[] = $message;
                    $months[substr($message['timestamp'], 0, 7)] = true;
                }
            }

            $dropped = $reserved - count($messages);
            if ($dropped === $reserved) {
                Log::warning('capture dropped every event of a request', [
                    'project_id' => $projectId,
                    'received' => $reserved,
                    'dropped' => $dropped,
                ]);
            } elseif ($dropped > 0) {
                Log::debug('capture dropped invalid events', [
                    'project_id' => $projectId,
                    'received' => $reserved,
                    'dropped' => $dropped,
                ]);
            }

            // 6. ClickHouse TOO_MANY_PARTS guard: partitions are monthly.
            if (count($months) > (int) config('capture.max_months_per_request')) {
                return $this->error(400, 'too_many_months', 'Events span too many calendar months for one request');
            }

            // 7. Produce.
            if ($messages !== []) {
                $producer->produce($messages);
            }
            $produced = count($messages);

            return response()->json(['status' => 1]);
        } catch (ProducerUnavailable) {
            return $this->unavailable(
                'service_unavailable',
                'Event pipeline is unavailable, retry later',
                max(1, (int) ceil((float) config('capture.kafka_breaker_cooldown_seconds'))),
            );
        } finally {
            // Only what was produced stays counted: dropped events, a rejected request and a Kafka outage
            // (which clients retry) give their reservation back.
            $projectLimiter->release($projectId, $reserved - $produced, $nowTs);
        }
    }

    private function ipDisabled(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower($value), ['0', 'false'], true);
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

    private function parseSentAt(?string $value): ?CarbonImmutable
    {
        if ($value === null || strlen($value) > 40 || preg_match(self::SENT_AT_FORMAT, $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
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
