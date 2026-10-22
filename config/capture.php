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
    'auth_fail_limit' => (int) env('CAPTURE_AUTH_FAIL_LIMIT', 30),
    'auth_fail_window_seconds' => (int) env('CAPTURE_AUTH_FAIL_WINDOW_SECONDS', 60),
];
