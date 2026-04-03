<?php

use App\Http\Controllers\SatelliteController;
use App\Http\Controllers\ConjunctionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Debris Monitor API Routes
|--------------------------------------------------------------------------
*/

// Health check — used by Docker HEALTHCHECK and uptime monitors
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'env'    => app()->environment(),
    'time'   => now()->toIso8601String(),
]));

// Satellite data
Route::prefix('satellites')->group(function () {

    // GET /api/satellites/{noradId}
    // Returns current TLE + propagated position
    Route::get('/{noradId}', [SatelliteController::class, 'show'])
        ->whereNumber('noradId');

    // GET /api/satellites/{noradId}/orbit
    // Returns orbital path points for the next N minutes
    Route::get('/{noradId}/orbit', [SatelliteController::class, 'orbit'])
        ->whereNumber('noradId');
});

// Conjunction / debris risk
Route::prefix('conjunctions')->group(function () {

    // GET /api/conjunctions/{noradId}
    // Returns nearby objects + risk scores
    Route::get('/{noradId}', [ConjunctionController::class, 'index'])
        ->whereNumber('noradId');
});
