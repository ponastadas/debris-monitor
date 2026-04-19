<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync satellite catalog from CelesTrak every 6 hours.
// Safe to run repeatedly — upserts satellites and rotates TLE records.
Schedule::command('satellites:sync')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/satellites.log'));

// Fetch real conjunction data from Space-Track CDM_PUBLIC every 6 hours.
// Requires SPACE_TRACK_USER / SPACE_TRACK_PASS in .env — exits cleanly if unset.
Schedule::command('conjunctions:sync')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/conjunctions.log'));

// SGP4-based conjunction screening — runs as a fallback/supplement to CDM sync.
// Screens watched satellites against local debris catalog using TlePropagator.
// Run the queue worker alongside this so notifications are dispatched promptly:
//   php artisan queue:work --queue=default
Schedule::command('conjunctions:check')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/conjunctions-sgp4.log'));
