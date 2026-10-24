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
    // outage cannot flood the log (same idea as capture.limiter_warning_interval_seconds).
    'cache_warning_interval_seconds' => 60,
];
