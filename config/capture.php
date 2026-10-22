<?php

return [
    'kafka_brokers' => env('KAFKA_BROKERS', 'kafka:9092'),
    'events_topic' => env('KAFKA_EVENTS_TOPIC', 'events'),
    'max_body_bytes' => 1_000_000,
    'max_decoded_bytes' => 2_000_000,
    'max_batch_size' => 500,
    'max_months_per_request' => 24,
];
