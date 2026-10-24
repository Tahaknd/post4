<?php

return [
    /*
     * At most one "rate limiter unavailable, failing open" warning per process and limiter family (capture,
     * login) per this many seconds, so a Redis outage cannot flood the log. See App\Support\RateLimit\LimiterOutage.
     */
    'warning_interval_seconds' => 60,

    /*
     * Web project writes (create, rotate key, delete) per signed-in user and window. Fail-open like the other
     * limiters (App\Domain\Projects\ProjectWriteThrottle).
     */
    'project_writes' => [
        'limit' => 30,
        'window_seconds' => 60,
    ],
];
