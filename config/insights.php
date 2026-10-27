<?php

return [
    // How long a successful insight result is served from the cache (seconds). Short on purpose: events keep
    // arriving, and an identical query inside this window is answered without touching ClickHouse. Errors are
    // never cached.
    // 0 switches the cache off.
    'cache_ttl_seconds' => (int) env('INSIGHTS_CACHE_TTL_SECONDS', 60),

    // Cache store of the insight results. Redis on purpose and independent of CACHE_STORE: with the `database`
    // store (the .env.example default for a bare install) every result would be written to Postgres. Its
    // `cache` connection has bounded connect/read timeouts (config/database.php), so a frozen Redis fails fast.
    'cache_store' => env('INSIGHTS_CACHE_STORE', 'redis'),

    // At most one "cache unavailable, running live" warning per process per this many seconds, so a Redis
    // outage cannot flood the log (same idea as ratelimit.warning_interval_seconds).
    'cache_warning_interval_seconds' => 60,

    /*
     * Asynchronous runs (App\Domain\Insights\Runs, App\Jobs\RunTrendsInsight). Every key lives in
     * `cache_store` with a TTL; nothing is ever flushed.
     */

    // How long a QUEUED or RUNNING run record lives (also the TTL of its dispatch slots). A run nobody collects expires;
    // polling an expired (or evicted) run answers 404, which a client must read as "expired, submit again".
    'run_ttl_seconds' => (int) env('INSIGHTS_RUN_TTL_SECONDS', 900),

    // How long a DONE, FAILED or CANCELLED run record (with its result) can still be polled.
    'finished_ttl_seconds' => (int) env('INSIGHTS_FINISHED_TTL_SECONDS', 300),

    // Largest JSON size of a result stored in a run record; a bigger one becomes a "too large" failure.
    'max_result_bytes' => (int) env('INSIGHTS_MAX_RESULT_BYTES', 1_000_000),

    // At most this many runs of one PROJECT exist at once (queued or running); more are answered 429 busy.
    'max_active_runs_per_project' => (int) env('INSIGHTS_MAX_ACTIVE_RUNS_PER_PROJECT', 20),

    // At most this many runs of one USER exist at once across ALL projects (an administrator can open every project,
    // so the per-project cap alone would let one account fill the queue); more are answered 429 busy.
    'max_active_runs_per_user' => (int) env('INSIGHTS_MAX_ACTIVE_RUNS_PER_USER', 8),

    // At most this many insight queries of one project run on ClickHouse at once. The `insights` Horizon supervisor
    // has 2 workers and the read-only user's profile allows max_concurrent_queries_for_user = 3 for ALL tenants
    // together: 2 workers < 3 ClickHouse slots leaves one spare slot for the interactive event-name list of the UI
    // (Task 3.7). With 1 per project, one busy tenant can never hold both workers; a job over the cap releases itself
    // (3 s) and its run stays queued.
    'max_concurrent_queries_per_project' => (int) env('INSIGHTS_MAX_CONCURRENT_QUERIES_PER_PROJECT', 1),

    // TTL of the running slots and the single-flight lock. Must stay ABOVE the job timeout (45 s) so a killed job
    // frees them by itself, and BELOW the Redis queue's retry_after (90 s) so a takeover finds them expired.
    'lock_ttl_seconds' => (int) env('INSIGHTS_LOCK_TTL_SECONDS', 50),

    // The inflight key of a run (an identical POST of the same user while it is queued or running returns the same
    // run id) and the marker with which a failed holder tells the waiters of the same definition (seconds).
    'inflight_ttl_seconds' => (int) env('INSIGHTS_INFLIGHT_TTL_SECONDS', 60),
    'flightfail_ttl_seconds' => (int) env('INSIGHTS_FLIGHTFAIL_TTL_SECONDS', 12),

    // Seconds a client waits between polls of a queued or running run (Retry-After of 202 and of the poll), and
    // the Retry-After of a 503 `unavailable`.
    'poll_interval_seconds' => (int) env('INSIGHTS_POLL_INTERVAL_SECONDS', 1),
    'unavailable_retry_after_seconds' => (int) env('INSIGHTS_UNAVAILABLE_RETRY_AFTER_SECONDS', 5),

    // The event-name list of the picker (Task 3.7, App\Domain\Insights\Events\EventNames). It is interactive, answered
    // from a per-project cache, and shares ONE ClickHouse slot of the read-only user between all tenants, so it has
    // its own limits:
    //  - cache TTL of an answer per project and normalised search text (0 switches the cache off); the list WITHOUT a
    //    search text, which every picker asks for first, lives longer;
    //  - the ClickHouse time limit of the query, in seconds (a LOWER value than the profile's 20 s; the profile allows 1-22);
    //  - the TTL of its locks (the slot, the per-project lock, single flight); the effective TTL is never below
    //    max_execution_seconds + 5 so a killed request always outlives its own query before it frees them;
    //  - how long a request may wait for the locks before it is answered 429 busy (milliseconds; 0 = never wait);
    //  - how much ClickHouse time one project's event lists may use per minute (milliseconds): past it the project is
    //    answered 429 busy until the minute rolls over, so one tenant typing or scripting cannot hold the slot.
    'events_cache_ttl_seconds' => (int) env('INSIGHTS_EVENTS_CACHE_TTL_SECONDS', 120),
    'events_empty_search_cache_ttl_seconds' => (int) env('INSIGHTS_EVENTS_EMPTY_SEARCH_CACHE_TTL_SECONDS', 300),
    'events_max_execution_seconds' => (int) env('INSIGHTS_EVENTS_MAX_EXECUTION_SECONDS', 3),
    'events_lock_ttl_seconds' => (int) env('INSIGHTS_EVENTS_LOCK_TTL_SECONDS', 15),
    'events_wait_ms' => (int) env('INSIGHTS_EVENTS_WAIT_MS', 1200),
    'events_project_budget_ms' => (int) env('INSIGHTS_EVENTS_PROJECT_BUDGET_MS', 10000),
];
