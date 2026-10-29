<?php

return [
    /*
     * The identity consumer (`php artisan identity:consume`, the `identity` compose service): reads the events topic
     * (config capture.events_topic) as consumer group `identity` and turns `$identify`, `$create_alias` and
     * `$merge_dangerously` into persons in Postgres and rows on the rows topic. The consumer group of ClickHouse
     * (capture.status.kafka_group) is a different one and is never touched.
     *
     *  - group:      IDENTITY_CONSUMER_GROUP. Changing it makes the consumer start again from the OLDEST retained
     *                event (harmless: the resolver ignores what it already applied), which is also how a replay is done.
     *  - rows_topic: IDENTITY_ROWS_TOPIC, the person_distinct_ids topic ClickHouse reads (shape: PersonDistinctIdRow).
     *  - dlq_topic:  IDENTITY_DLQ_TOPIC, events the consumer cannot process (shape: DeadLetter).
     *  - max_memory_mb: IDENTITY_MAX_MEMORY_MB; the process finishes the current message, commits and exits 0 above
     *                this (the supervisor starts a new one). PHP's own memory_limit is 256M.
     *  - retry_*:    a retryable failure of one step is repeated after retry_base_ms, doubling up to retry_max_ms, for at
     *                most retry_attempts attempts; then the process exits 1 without committing past the message.
     */
    'group' => env('IDENTITY_CONSUMER_GROUP', 'identity'),
    'rows_topic' => env('IDENTITY_ROWS_TOPIC', 'person_distinct_ids'),
    'dlq_topic' => env('IDENTITY_DLQ_TOPIC', 'events_dlq'),
    'max_memory_mb' => (int) env('IDENTITY_MAX_MEMORY_MB', 192),
    'summary_interval_seconds' => 30,
    'retry_attempts' => 12,
    'retry_base_ms' => 500,
    'retry_max_ms' => 30000,
    'produce_timeout_ms' => 30000,
];
