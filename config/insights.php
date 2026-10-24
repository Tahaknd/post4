<?php

return [
    // How long a successful insight result is served from the cache (seconds). Short on purpose: events keep
    // arriving, and an identical query inside this window is answered without touching ClickHouse. Errors are
    // never cached.
    'cache_ttl_seconds' => (int) env('INSIGHTS_CACHE_TTL', 60),

    // At most one "cache unavailable, running live" warning per process per this many seconds, so a Redis
    // outage cannot flood the log (same idea as capture.limiter_warning_interval_seconds).
    'cache_warning_interval_seconds' => 60,
];
