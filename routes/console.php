<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Ingest health every minute (HTTP -> Kafka -> ClickHouse). --log writes one summary line (status, max partition
// lag, disk free percent) to the application log. It runs IN the scheduler process (Schedule::call, not
// Schedule::command): a command event is spawned with its output sent to /dev/null, which would swallow the
// stderr log line, while this way the line reaches `docker compose logs scheduler`. The run takes seconds; a
// crashed run releases the overlap mutex after 5 minutes instead of the 24 hours default.
Schedule::call(fn () => Artisan::call('capture:status', ['--log' => true]))
    ->name('capture:status')
    ->everyMinute()
    ->withoutOverlapping(5);

// Events of project ids that no longer exist in Postgres (late Kafka events, failed purges, --keep-events).
Schedule::command('events:sweep-orphans')->dailyAt('03:30')->withoutOverlapping();

// Keeps the saved insights of recently viewed dashboards warm (App\Domain\Dashboards\Refresh\DashboardRefresh): queues low
// priority runs, never queries ClickHouse itself. Like capture:status it runs IN the scheduler process (Schedule::call), so its
// one summary line reaches `docker compose logs scheduler`. A tick only queues, so it is quick; a crashed one releases the
// overlap mutex after 10 minutes. Off with DASHBOARDS_REFRESH_ENABLED=false (then it is not even registered); the interval is
// DASHBOARDS_REFRESH_INTERVAL_MINUTES (1 to 59).
if ((bool) config('dashboards.refresh.enabled')) {
    $dashboardRefreshMinutes = max(1, min(59, (int) config('dashboards.refresh.interval_minutes')));

    Schedule::call(fn () => Artisan::call('dashboards:refresh', ['--log' => true]))
        ->name('dashboards:refresh')
        ->cron("*/{$dashboardRefreshMinutes} * * * *")
        ->withoutOverlapping(10);
}
