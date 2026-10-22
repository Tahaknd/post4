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
    'auth_fail_limit' => (int) env('CAPTURE_AUTH_FAIL_LIMIT', 120),
    'auth_fail_window_seconds' => (int) env('CAPTURE_AUTH_FAIL_WINDOW_SECONDS', 60),

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
