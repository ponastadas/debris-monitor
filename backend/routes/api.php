<?php

use App\Http\Controllers\Admin\AdminApiKeyController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\AdminSubscriptionController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ConjunctionController;
use App\Http\Controllers\SatelliteController;
use App\Http\Controllers\WatchedSatelliteController;
use App\Http\Middleware\HandlePublicRequest;
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

// ── Authentication (rate-limited: 5/min per IP) ───────────────────────────────
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register',        [AuthController::class, 'register']);
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
});

// ── Authenticated routes (Sanctum bearer token) ───────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth utilities
    Route::post('/auth/logout',       [AuthController::class, 'logout']);
    Route::get('/auth/me',            [AuthController::class, 'me']);
    Route::patch('/auth/me',          [AuthController::class, 'updateProfile']);
    Route::patch('/auth/password',    [AuthController::class, 'updatePassword']);

    // Billing (mock mode — real Stripe integration coming soon)
    Route::prefix('billing')->group(function () {
        Route::get('/plan',      [BillingController::class, 'currentPlan']);
        Route::get('/history',   [BillingController::class, 'paymentHistory']);
        Route::post('/subscribe',[BillingController::class, 'subscribe']);
        Route::post('/cancel',   [BillingController::class, 'cancelSubscription']);
        // TODO: Route::post('/portal', [BillingController::class, 'portal']); — enable when Stripe is configured
    });

    // API key management
    Route::get('/keys',         [ApiKeyController::class, 'index']);
    Route::post('/keys',        [ApiKeyController::class, 'store']);
    Route::delete('/keys/{id}', [ApiKeyController::class, 'destroy']);

    // Watched satellites
    Route::get('/watch',          [WatchedSatelliteController::class, 'index']);
    Route::post('/watch',         [WatchedSatelliteController::class, 'store']);
    Route::delete('/watch/{id}',  [WatchedSatelliteController::class, 'destroy']);

    // Conjunction alerts for the user's watched satellites
    Route::get('/alerts', [AlertController::class, 'index']);

    // ── Admin panel (requires admin role) ────────────────────────────────────
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/dashboard',                      [AdminDashboardController::class, 'index']);

        Route::get('/users',                          [AdminUserController::class, 'index']);
        Route::get('/users/{user}',                   [AdminUserController::class, 'show']);
        Route::patch('/users/{user}',                 [AdminUserController::class, 'update']);
        Route::post('/users/{user}/impersonate',      [AdminUserController::class, 'impersonate']);

        Route::get('/subscriptions',                  [AdminSubscriptionController::class, 'index']);

        Route::get('/payments',                       [AdminPaymentController::class, 'index']);
        Route::post('/payments/{payment}/refund',     [AdminPaymentController::class, 'refund']);

        Route::get('/api-keys',                       [AdminApiKeyController::class, 'index']);
    });
});

// ── Stripe webhook ─────────────────────────────────────────────────────────────
// TODO: uncomment when Stripe is configured
// Route::post('/stripe/webhook', '\Laravel\Cashier\Http\Controllers\WebhookController@handleWebhook');

// ── Satellite data (guest · Sanctum user · API key) ───────────────────────────
// HandlePublicRequest accepts all three actor types.
// Guests: 10 analyses/day. Registered users: unlimited web access. API keys: tier limits.
Route::middleware(HandlePublicRequest::class)->group(function () {

    Route::prefix('satellites')->group(function () {
        Route::get('/{noradId}',       [SatelliteController::class, 'show'])->whereNumber('noradId');
        Route::get('/{noradId}/orbit', [SatelliteController::class, 'orbit'])->whereNumber('noradId');
    });

    Route::prefix('conjunctions')->group(function () {
        Route::get('/{noradId}', [ConjunctionController::class, 'index'])->whereNumber('noradId');
    });
});
