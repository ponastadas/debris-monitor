<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\ConjunctionController;
use App\Http\Controllers\SatelliteController;
use App\Http\Controllers\WatchedSatelliteController;
use App\Http\Middleware\AuthenticateApiKey;
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

// User-facing routes — require Sanctum session/token auth
Route::middleware('auth:sanctum')->group(function () {
    // API key management
    Route::get('/keys', [ApiKeyController::class, 'index']);
    Route::post('/keys', [ApiKeyController::class, 'store']);
    Route::delete('/keys/{id}', [ApiKeyController::class, 'destroy']);

    // Watched satellites
    Route::get('/watch', [WatchedSatelliteController::class, 'index']);
    Route::post('/watch', [WatchedSatelliteController::class, 'store']);
    Route::delete('/watch/{id}', [WatchedSatelliteController::class, 'destroy']);

    // Conjunction alerts for the user's watched satellites
    Route::get('/alerts', [AlertController::class, 'index']);
});

// Satellite data — requires API key
Route::middleware(AuthenticateApiKey::class)->group(function () {

    Route::prefix('satellites')->group(function () {
        // GET /api/satellites/{noradId}
        Route::get('/{noradId}', [SatelliteController::class, 'show'])
            ->whereNumber('noradId');

        // GET /api/satellites/{noradId}/orbit
        Route::get('/{noradId}/orbit', [SatelliteController::class, 'orbit'])
            ->whereNumber('noradId');
    });

    Route::prefix('conjunctions')->group(function () {
        // GET /api/conjunctions/{noradId}
        Route::get('/{noradId}', [ConjunctionController::class, 'index'])
            ->whereNumber('noradId');
    });
});
