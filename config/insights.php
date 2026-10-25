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

    // How long a QUEUED or RUNNING run record lives (also the TTL of its dispatch slot). A run nobody collects expires.
    'run_ttl_seconds' => 900,

    // How long a DONE or FAILED run record (with its result) can still be polled.
    'finished_ttl_seconds' => 300,

    // Largest JSON size of a result stored in a run record; a bigger one becomes a "too large" failure.
    'max_result_bytes' => 1_000_000,

    // At most this many runs of one project exist at once (queued or running); more are answered 429.
    'max_runs_per_project' => 20,

    // At most this many insight queries of one project run on ClickHouse at once; a job over the cap releases itself
    // (3 s) and its run stays queued. The `insights` Horizon supervisor has maxProcesses 2 and the read-only user's
    // profile allows max_concurrent_queries_for_user = 3 for ALL tenants together: 2 < 3 leaves one ClickHouse slot
    // for the interactive event-name list of the UI (Task 3.7). Keep supervisor processes below that limit.
    'max_running_per_project' => 2,

    // TTL of the running slots and the single-flight lock. Must stay above the job timeout (45 s).
    'flight_lock_seconds' => 60,
];
