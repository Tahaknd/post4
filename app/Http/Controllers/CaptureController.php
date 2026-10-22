<?php

namespace App\Http\Controllers;

use App\Domain\Capture\EventNormalizer;
use App\Domain\Capture\EventProducer;
use App\Domain\Capture\InvalidPayload;
use App\Domain\Capture\PayloadDecoder;
use App\Domain\Capture\ProducerUnavailable;
use App\Domain\Capture\ProjectResolver;
use App\Domain\Capture\TooManyEvents;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

final class CaptureController extends Controller
{
    /** Strict ISO-8601 / SQL-ish timestamp; anything else (e.g. "tomorrow") is ignored. */
    private const SENT_AT_FORMAT = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})?$/';

    private const LIMITER_WARNING_INTERVAL_SECONDS = 60;

    /** Unix time of the last "limiter unavailable" warning, so a Redis outage cannot flood the log. */
    private static int $lastLimiterWarningAt = 0;

    public function __invoke(
        Request $request,
        PayloadDecoder $decoder,
        ProjectResolver $projects,
        EventNormalizer $normalizer,
        EventProducer $producer,
    ): JsonResponse {
        // 1. Body size: the declared length first (cheap), then the real body.
        $maxBody = (int) config('capture.max_body_bytes');
        $declared = $request->header('Content-Length');
        if ((is_string($declared) && ctype_digit($declared) && (int) $declared > $maxBody)
            || strlen($request->getContent()) > $maxBody) {
            return $this->error(413, 'payload_too_large', 'Request body is too large');
        }

        // Cheap per-IP guard against key guessing, before any decoding or lookup.
        $limiterKey = 'capture-auth-fail:'.($request->ip() ?? 'unknown');
        $retryAfter = $this->blockedFor($limiterKey);
        if ($retryAfter !== null) {
            return $this->error(429, 'too_many_requests', 'Too many failed attempts, retry later')
                ->header('Retry-After', (string) $retryAfter);
        }

        // 2. Decode.
        try {
            $payload = $decoder->decode($request);
        } catch (TooManyEvents) {
            $this->recordFailure($limiterKey);

            return $this->error(413, 'batch_too_large', 'Too many events in one batch');
        } catch (InvalidPayload $e) {
            $this->recordFailure($limiterKey);

            return $this->error(400, 'invalid_payload', $e->getMessage());
        }

        // 3. Nothing to do.
        if ($payload->events === []) {
            $this->recordFailure($limiterKey);

            return $this->error(400, 'no_events', 'No events in payload');
        }

        // 4. Authenticate before normalizing or producing anything.
        try {
            $projectId = $payload->apiKey !== null ? $projects->resolve($payload->apiKey) : null;
        } catch (\Throwable $e) {
            Log::error('capture project lookup failed', ['exception' => $e::class]);

            return $this->error(503, 'service_unavailable', 'Project lookup is unavailable, retry later')
                ->header('Retry-After', '5');
        }
        if ($projectId === null) {
            $this->recordFailure($limiterKey);

            return $this->error(401, 'invalid_api_key', 'API key is missing or invalid');
        }

        $now = CarbonImmutable::now('UTC');
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

        $received = count($payload->events);
        $dropped = $received - count($messages);
        if ($dropped === $received) {
            Log::warning('capture dropped every event of a request', [
                'project_id' => $projectId,
                'received' => $received,
                'dropped' => $dropped,
            ]);
        } elseif ($dropped > 0) {
            Log::debug('capture dropped invalid events', [
                'project_id' => $projectId,
                'received' => $received,
                'dropped' => $dropped,
            ]);
        }

        // 5. ClickHouse TOO_MANY_PARTS guard: partitions are monthly.
        if (count($months) > (int) config('capture.max_months_per_request')) {
            return $this->error(400, 'too_many_months', 'Events span too many calendar months for one request');
        }

        // 6. Produce.
        try {
            if ($messages !== []) {
                $producer->produce($messages);
            }
        } catch (ProducerUnavailable) {
            return $this->error(503, 'service_unavailable', 'Event pipeline is unavailable, retry later')
                ->header('Retry-After', (string) max(1, (int) ceil((float) config('capture.kafka_breaker_cooldown_seconds'))));
        }

        return response()->json(['status' => 1]);
    }

    private function ipDisabled(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower($value), ['0', 'false'], true);
    }

    /** Seconds until the caller may retry, or null when it is not blocked. Fails open if the limiter is down. */
    private function blockedFor(string $limiterKey): ?int
    {
        try {
            if (! RateLimiter::tooManyAttempts($limiterKey, (int) config('capture.auth_fail_limit'))) {
                return null;
            }

            return max(1, RateLimiter::availableIn($limiterKey));
        } catch (\Throwable $e) {
            $this->warnLimiterUnavailable($e);

            return null;
        }
    }

    /** Counts a failed (unauthenticated or undecodable) request against the caller's ip. */
    private function recordFailure(string $limiterKey): void
    {
        $limit = (int) config('capture.auth_fail_limit');

        try {
            $attempts = RateLimiter::hit($limiterKey, (int) config('capture.auth_fail_window_seconds'));
        } catch (\Throwable $e) {
            $this->warnLimiterUnavailable($e);

            return;
        }

        if ($attempts === $limit) {
            Log::warning('capture failure limit reached, blocking caller', ['limiter_key' => $limiterKey, 'limit' => $limit]);
        }
    }

    private function warnLimiterUnavailable(\Throwable $e): void
    {
        $now = time();
        if ($now - self::$lastLimiterWarningAt < self::LIMITER_WARNING_INTERVAL_SECONDS) {
            return;
        }
        self::$lastLimiterWarningAt = $now;

        Log::warning('capture rate limiter unavailable, failing open', ['exception' => $e::class]);
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

    private function error(int $status, string $code, string $detail): JsonResponse
    {
        return response()->json(['type' => 'capture_error', 'code' => $code, 'detail' => $detail], $status);
    }
}
