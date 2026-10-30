<?php

return [
    /*
     * Limits per project. Enforced inside the write actions under a row lock (the project row for the first two, the
     * dashboard row for tiles), so two concurrent requests can never both take the last place.
     */
    'max_saved_insights_per_project' => (int) env('DASHBOARDS_MAX_SAVED_INSIGHTS_PER_PROJECT', 200),
    'max_dashboards_per_project' => (int) env('DASHBOARDS_MAX_DASHBOARDS_PER_PROJECT', 50),
    'max_tiles_per_dashboard' => (int) env('DASHBOARDS_MAX_TILES_PER_DASHBOARD', 30),

    // Page size of the list endpoints (the default, and the largest `per_page` a client may ask for).
    'default_page_size' => 50,
    'max_page_size' => 100,

    // GET of a dashboard records `last_viewed_at`, but at most one write per dashboard per this many seconds.
    'view_touch_seconds' => (int) env('DASHBOARDS_VIEW_TOUCH_SECONDS', 300),

    /*
     * The scheduled refresh (`dashboards:refresh`, App\Domain\Dashboards\DashboardRefresh): keeps the saved insights of
     * RECENTLY VIEWED dashboards in the dashboard result cache, so opening a dashboard is answered from Redis.
     */
    'refresh' => [
        // The switch. Off: the command does nothing and the scheduler does not even register it.
        'enabled' => (bool) env('DASHBOARDS_REFRESH_ENABLED', true),

        // Minutes between two ticks (1 to 59; the schedule is `*/N * * * *`).
        'interval_minutes' => (int) env('DASHBOARDS_REFRESH_INTERVAL_MINUTES', 15),

        // Only dashboards opened within this many days are refreshed; at most `max_dashboards` of them are looked at per
        // tick (the most recently viewed first).
        'viewed_within_days' => (int) env('DASHBOARDS_REFRESH_VIEWED_WITHIN_DAYS', 3),
        'max_dashboards' => (int) env('DASHBOARDS_REFRESH_MAX_DASHBOARDS', 200),

        // Budget of one tick: queries queued in total and per project. A project's insights beyond its share wait for
        // the next tick (the oldest cached result goes first, so every insight gets its turn).
        'max_insights_per_tick' => (int) env('DASHBOARDS_REFRESH_MAX_INSIGHTS_PER_TICK', 30),
        'max_insights_per_project_per_tick' => (int) env('DASHBOARDS_REFRESH_MAX_INSIGHTS_PER_PROJECT_PER_TICK', 5),

        // Each queued refresh starts a random 0..N seconds later, so a tick is not one burst on the insights workers.
        'jitter_seconds' => (int) env('DASHBOARDS_REFRESH_JITTER_SECONDS', 60),

        // The tick is skipped when more than this many insight jobs (both queues) are already waiting.
        'max_queue_backlog' => (int) env('DASHBOARDS_REFRESH_MAX_QUEUE_BACKLOG', 10),

        // The queue of the refresh jobs: served by the insights supervisor AFTER `insights` (config/horizon.php), so a
        // person who waits for a chart is always served first.
        'queue' => 'insights-refresh',

        // FRESHNESS. A result in the dashboard cache younger than `stale_after_seconds` is not recomputed; the default
        // is a little under the interval, so every tick renews what the previous one left (a tick that was skipped
        // leaves nothing older than ~2 intervals). `result_ttl_seconds` is how long the cache keeps a result: two
        // intervals and a margin, so one missed tick still leaves a warm dashboard. The interactive result cache
        // (insights.cache_ttl_seconds, 60 s) is independent and unchanged: only saved-insight runs and the refresh read
        // and write the dashboard cache, and every answer carries its `computed_at`.
        'stale_after_seconds' => (int) env('DASHBOARDS_REFRESH_STALE_AFTER_SECONDS', 840),
        'result_ttl_seconds' => (int) env('DASHBOARDS_REFRESH_RESULT_TTL_SECONDS', 2100),
    ],
];
