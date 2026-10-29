<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Ingest health every minute (HTTP -> Kafka -> ClickHouse): --log writes one summary line (status, max partition
// lag, disk free percent) to the application log, i.e. to `docker compose logs`. The run takes seconds; a
// crashed run releases the overlap mutex after 5 minutes instead of the 24 hours default.
Schedule::command('capture:status --log')->everyMinute()->withoutOverlapping(5);

// Events of project ids that no longer exist in Postgres (late Kafka events, failed purges, --keep-events).
Schedule::command('events:sweep-orphans')->dailyAt('03:30')->withoutOverlapping();
