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

    /*
     * Keep the raw client ip in the stored event properties as `$ip` (env CAPTURE_STORE_IP, default false).
     * DEVIATION FROM POSTHOG, which stores `$ip` unless a project discards it: here the address is used for the
     * GeoIP lookup (when a database is installed) and then dropped, so the event store holds no IP addresses.
     * An `$ip` an SDK sends itself is dropped too, also inside `$set` and `$set_once`. `?ip=0` on the request
     * means "no ip at all": no GeoIP lookup (even for a body `$ip`) and no client ip added.
     * All of `store_ip`, `store_raw_user_agent` and the enrichment keys below are read ONCE when the Enricher
     * singleton is built (worker boot, see config/octane.php `warm`): changing the env needs a restart or
     * `php artisan octane:reload`, not just the next request.
     */
    'store_ip' => (bool) env('CAPTURE_STORE_IP', false),

    /*
     * Keep the `$raw_user_agent` an SDK may send (env CAPTURE_STORE_RAW_UA, default false). It is used to derive
     * browser, os and device and then dropped, like the ip, because a User-Agent can identify a person. When kept
     * it is capped at 512 bytes. The request's own User-Agent HEADER is never stored.
     */
    'store_raw_user_agent' => (bool) env('CAPTURE_STORE_RAW_UA', false),

    /*
     * Enrichment of captured events (App\Domain\Capture\Enricher): browser, os and device properties from the
     * User-Agent, and `$geoip_*` properties from an OPTIONAL operator supplied MaxMind DB file. It only FILLS
     * properties the SDK left out and never rejects an event.
     *  - enabled: CAPTURE_ENRICHMENT_ENABLED (default true); false skips the User-Agent and GeoIP work (the ip and
     *    raw User-Agent handling above still applies).
     *  - ua_cache_entries / ua_cache_max_bytes: bound of the per worker memo of parsed User-Agents
     *    (CAPTURE_UA_CACHE_ENTRIES, CAPTURE_UA_CACHE_MAX_BYTES). A full default memo is roughly 1.5 MB per worker
     *    and never more than the byte bound.
     *  - max_ua_parses_per_request: CAPTURE_UA_PARSES_PER_REQUEST (default 20), the most DISTINCT User-Agent
     *    strings the events of ONE request may make the library parse. Events carrying their own
     *    `$raw_user_agent` can otherwise make a 500 event batch cost 500 parses; over the budget an event gets
     *    the request header's result. Memo hits, repeats and the header itself never count.
     *  - geoip_path: GEOIP_DB_PATH, a GeoLite2-City or DB-IP Lite City `.mmdb`. No file means no `$geoip_*`
     *    properties and no error; the file is picked up (and a replaced one reopened) within
     *    geoip_retry_seconds (GEOIP_RETRY_SECONDS, default 60) without a restart. The database is NEVER bundled:
     *    licences. Check a file with `php artisan geoip:check`.
     */
    'enrichment' => [
        'enabled' => (bool) env('CAPTURE_ENRICHMENT_ENABLED', true),
        'ua_cache_entries' => (int) env('CAPTURE_UA_CACHE_ENTRIES', 2000),
        'ua_cache_max_bytes' => (int) env('CAPTURE_UA_CACHE_MAX_BYTES', 2 * 1024 * 1024),
        'max_ua_parses_per_request' => (int) env('CAPTURE_UA_PARSES_PER_REQUEST', 20),
        'geoip_path' => (string) env('GEOIP_DB_PATH', storage_path('geoip/geoip.mmdb')),
        'geoip_retry_seconds' => (int) env('GEOIP_RETRY_SECONDS', 60),
    ],

    /*
     * `capture:status` thresholds (see App\Domain\Capture\IngestHealth). The two disk knobs exist because a
     * small dev box can legitimately run below the defaults; production should keep them.
     */
    'status' => [
        'kafka_group' => env('CAPTURE_STATUS_KAFKA_GROUP', 'clickhouse_events'),
        'kafka_timeout_ms' => 3000,
        'min_free_percent' => (float) env('CAPTURE_STATUS_MIN_FREE_PERCENT', 10),
        'min_free_bytes' => (int) env('CAPTURE_STATUS_MIN_FREE_BYTES', 2 * 1024 ** 3),
        'snapshot_ttl_seconds' => 86400,
    ],
];
