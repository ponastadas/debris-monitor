<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync satellite catalog from Space-Track GP endpoint — 4×/day.
// Deliberately offset from :00/:30 per Space-Track API usage policy.
// Times (UTC): 01:17, 07:17, 13:17, 19:17
Schedule::command('satellites:sync')
    ->cron('17 1,7,13,19 * * *')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/satellites.log'));

// Fetch real conjunction data from Space-Track CDM_PUBLIC — 3×/day (max allowed).
// CDM policy: once every 8 hours. Times (UTC): 02:23, 10:23, 18:23.
// Deliberately offset from :00/:30 per Space-Track API usage policy.
// Requires SPACE_TRACK_USER / SPACE_TRACK_PASS in .env — exits cleanly if unset.
Schedule::command('conjunctions:sync')
    ->cron('23 2,10,18 * * *')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/conjunctions.log'));

// Daily database backup — kept for 7 days, stored in storage/app/backups/.
Schedule::command('db:backup')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/db-backup.log'));

// SGP4-based conjunction screening — runs as a fallback/supplement to CDM sync.
// Screens watched satellites against local debris catalog using TlePropagator.
// Times (UTC): 03:47, 09:47, 15:47, 21:47 — offset from :00/:30.
// Run the queue worker alongside this so notifications are dispatched promptly:
//   php artisan queue:work --queue=default
Schedule::command('conjunctions:check')
    ->cron('47 3,9,15,21 * * *')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/conjunctions-sgp4.log'));
