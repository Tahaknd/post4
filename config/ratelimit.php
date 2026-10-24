<?php

return [
    /*
     * At most one "rate limiter unavailable, failing open" warning per process and limiter family (capture,
     * login) per this many seconds, so a Redis outage cannot flood the log. See App\Support\RateLimit\LimiterOutage.
     */
    'warning_interval_seconds' => 60,

    /*
     * Per signed-in user and window, one bucket per family (App\Http\Middleware\ThrottleUser, route middleware
     * `throttle.user:{family}`; fail-open like the other limiters, App\Support\RateLimit\UserThrottle).
     */
    'user_throttles' => [
        'projects.create' => ['limit' => 30, 'window_seconds' => 60],
        'projects.rotate' => ['limit' => 30, 'window_seconds' => 60],
        'projects.delete' => ['limit' => 30, 'window_seconds' => 60],
    ],
];
