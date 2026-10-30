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
        // Insight API: starting a run is the expensive call, polling it is cheap and happens about once a second
        // while a chart loads (several charts at once on a dashboard).
        'insights.run' => ['limit' => 60, 'window_seconds' => 60],
        'insights.poll' => ['limit' => 600, 'window_seconds' => 60],
        'insights.cancel' => ['limit' => 120, 'window_seconds' => 60],
        // The event picker asks while someone types (debounced, answered from a per-project cache): generous, but a
        // script cannot hammer ClickHouse's one spare slot.
        'insights.events' => ['limit' => 120, 'window_seconds' => 60],
        // Saved insights and dashboards: a dashboard page reads one dashboard and runs every tile (those runs are
        // `insights.run`); writes are rare and hand-made.
        'dashboards.read' => ['limit' => 300, 'window_seconds' => 60],
        'dashboards.write' => ['limit' => 60, 'window_seconds' => 60],
    ],
];
