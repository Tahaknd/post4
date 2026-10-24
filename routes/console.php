<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Events of project ids that no longer exist in Postgres (late Kafka events, failed purges, --keep-events).
Schedule::command('events:sweep-orphans')->dailyAt('03:30')->withoutOverlapping();
