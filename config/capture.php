<?php

return [
    'kafka_brokers' => env('KAFKA_BROKERS', 'kafka:9092'),
    'events_topic' => env('KAFKA_EVENTS_TOPIC', 'events'),
    'kafka_message_timeout_ms' => (int) env('KAFKA_MESSAGE_TIMEOUT_MS', 2500),
    'kafka_flush_timeout_ms' => (int) env('KAFKA_FLUSH_TIMEOUT_MS', 3000),
    'kafka_breaker_cooldown_seconds' => (float) env('KAFKA_BREAKER_COOLDOWN_SECONDS', 10),
    'max_body_bytes' => 1_000_000,
    'max_decoded_bytes' => 2_000_000,
    'max_batch_size' => 500,
    'max_containers' => 100_000,
    'max_months_per_request' => 24,
    /*
     * Per client ip limiter for UNAUTHENTICATED outcomes only (bad or missing key, undecodable body,
     * oversized or empty batch): at most auth_fail_limit failures per auth_fail_window_seconds (env
     * CAPTURE_AUTH_FAIL_LIMIT / CAPTURE_AUTH_FAIL_WINDOW_SECONDS), then 429 + Retry-After for that ip.
     * Requests with a valid key never touch it. It is a fixed window, so up to ~2x the limit can pass
     * across a window boundary. The smoke script's slow-POST check spends one failure per connection.
     */
    'auth_fail_limit' => (int) env('CAPTURE_AUTH_FAIL_LIMIT', 120),
    'auth_fail_window_seconds' => (int) env('CAPTURE_AUTH_FAIL_WINDOW_SECONDS', 60),

    /*
     * Interim per-project ingest cap: producible events accepted per fixed one-minute window (env
     * CAPTURE_PROJECT_EVENTS_PER_MINUTE). The public key is readable from any web page, so without it
     * a valid key can post unlimited events. 0 or negative disables the cap.
     *
     * Read it as a FLOOD CEILING for Kafka/ClickHouse, not as a storage or CPU guard: 60000/min is about
     * 86 million events/day per project. Notes:
     *  - the window is fixed, so up to ~2x the limit can be accepted across a window boundary;
     *  - a 503 `rate_limited` looks like an outage to passive proxies and monitors; the JSON `code`
     *    ("rate_limited" vs "service_unavailable") tells them apart, and Retry-After is set;
     *  - a busy project (about 1000 events/s) will see 503s at the default: raise the limit for it;
     *  - an enabled limit is never lower than max_batch_size (effective limit = max(limit, max_batch_size)).
     *    Otherwise a full batch could never fit, every attempt would be rejected and SDKs would retry
     *    it forever.
     */
    'project_events_per_minute' => (int) env('CAPTURE_PROJECT_EVENTS_PER_MINUTE', 60000),

    /* At most one "limiter unavailable, failing open" warning per process per this many seconds, so a Redis outage cannot flood the log. */
    'limiter_warning_interval_seconds' => 60,

    /*
     * Reverse proxies whose X-Forwarded-* headers are trusted (env TRUSTED_PROXIES):
     * unset/empty => null (trust nothing, so a direct client cannot spoof its ip),
     * "*" => every caller, otherwise a comma-separated list of IPs/CIDRs.
     */
    'trusted_proxies' => (static function (): array|string|null {
        $raw = trim((string) env('TRUSTED_PROXIES', ''));

        if ($raw === '') {
            return null;
        }
        if ($raw === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $p): bool => $p !== ''));
    })(),
];
