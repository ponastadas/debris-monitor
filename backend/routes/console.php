<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

// Screen watched satellites for upcoming conjunctions every 6 hours.
// Run the queue worker alongside this so notifications are dispatched promptly:
//   php artisan queue:work --queue=default
Schedule::command('conjunctions:check')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/conjunctions.log'));
