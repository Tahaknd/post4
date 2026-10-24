<?php

return [
    /*
     * At most one "rate limiter unavailable, failing open" warning per process and limiter family (capture,
     * login) per this many seconds, so a Redis outage cannot flood the log. See App\Support\RateLimit\LimiterOutage.
     */
    'warning_interval_seconds' => 60,
];
