<?php

use App\Http\Controllers\Admin\AdminApiKeyController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Admin\AdminAuditLogController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminMfaController;
use App\Http\Controllers\Admin\AdminPageController;
use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\AdminSubscriptionController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ConjunctionController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SatelliteController;
use App\Http\Controllers\SatelliteSearchController;
use App\Http\Controllers\WatchedSatelliteController;
use App\Http\Middleware\HandlePublicRequest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Debris Monitor API Routes
|--------------------------------------------------------------------------
*/

// ── Public CMS pages ──────────────────────────────────────────────────────────
// Read-only. Only published pages are returned.
Route::prefix('pages')->group(function () {
    Route::get('/',        [PageController::class, 'index']);
    Route::get('/{slug}',  [PageController::class, 'show'])->where('slug', '[a-z0-9-]+');
});

// Satellite catalog — full local TLE catalog for the globe view.
// Public, no auth, no rate limit. Cache-Control: public, max-age=3600.
// Returns empty array when catalog has not been synced yet (run satellites:sync).
Route::get('/catalog', [CatalogController::class, 'index']);

// Health check — used by Docker HEALTHCHECK and uptime monitors
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'env'    => app()->environment(),
    'time'   => now()->toIso8601String(),
]));

// ── Customer authentication ────────────────────────────────────────────────────
// Registration has its own limiter so it can be tuned independently of login.
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:registration');

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
});

// ── Customer authenticated routes (Sanctum bearer token) ─────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth utilities
    Route::post('/auth/logout',    [AuthController::class, 'logout']);
    Route::get('/auth/me',         [AuthController::class, 'me']);
    Route::patch('/auth/me',       [AuthController::class, 'updateProfile']);
    Route::patch('/auth/password', [AuthController::class, 'updatePassword']);

    // Billing (mock mode — real Stripe integration coming soon)
    Route::prefix('billing')->group(function () {
        Route::get('/plan',       [BillingController::class, 'currentPlan']);
        Route::get('/history',    [BillingController::class, 'paymentHistory']);
        Route::post('/subscribe', [BillingController::class, 'subscribe']);
        Route::post('/cancel',    [BillingController::class, 'cancelSubscription']);
    });

    // API key management
    Route::get('/keys',         [ApiKeyController::class, 'index']);
    Route::post('/keys',        [ApiKeyController::class, 'store']);
    Route::delete('/keys/{id}', [ApiKeyController::class, 'destroy']);

    // Watched satellites
    Route::get('/watch',         [WatchedSatelliteController::class, 'index']);
    Route::post('/watch',        [WatchedSatelliteController::class, 'store']);
    Route::delete('/watch/{id}', [WatchedSatelliteController::class, 'destroy']);

    // Conjunction alerts for the user's watched satellites
    Route::get('/alerts', [AlertController::class, 'index']);
});

// ── Admin authentication (public — stricter rate limit: 3/min per IP) ────────
// All routes in this group are part of the admin auth flow; none issues a
// session token until credentials + MFA (or forced MFA setup) succeed.
Route::prefix('admin/auth')->middleware('throttle:admin-login')->group(function () {
    Route::post('/login',            [AdminAuthController::class, 'login']);

    // Forced MFA setup flow — only valid with a setup_token issued by /login
    // when the admin account has no MFA configured.
    Route::post('/mfa/setup-init',     [AdminAuthController::class, 'setupInit']);
    Route::post('/mfa/setup-finalize', [AdminAuthController::class, 'setupFinalize']);
});

// Admin MFA verification step (separate rate limiter: 5/15min per IP)
Route::post('/admin/auth/mfa/verify', [AdminAuthController::class, 'mfaVerify'])
    ->middleware('throttle:admin-mfa');

// ── Admin panel (admin Sanctum token required) ────────────────────────────────
// auth:admin validates the Bearer token against the admin_accounts table.
// admin middleware additionally blocks inactive admin accounts.
Route::prefix('admin')->middleware(['auth:admin', 'admin'])->group(function () {
    Route::post('/auth/logout', [AdminAuthController::class, 'logout']);
    Route::get('/auth/me',      [AdminAuthController::class, 'me']);

    Route::get('/dashboard',                  [AdminDashboardController::class, 'index']);

    Route::get('/users',                      [AdminUserController::class, 'index']);
    Route::post('/users',                     [AdminUserController::class, 'store']);
    Route::get('/users/{user}',               [AdminUserController::class, 'show']);
    Route::patch('/users/{user}',             [AdminUserController::class, 'update']);
    Route::post('/users/{user}/impersonate',  [AdminUserController::class, 'impersonate']);

    Route::get('/subscriptions',              [AdminSubscriptionController::class, 'index']);

    Route::get('/payments',                   [AdminPaymentController::class, 'index']);
    Route::post('/payments/{payment}/refund', [AdminPaymentController::class, 'refund']);

    Route::get('/api-keys',                   [AdminApiKeyController::class, 'index']);

    Route::get('/audit-log',                  [AdminAuditLogController::class, 'index']);

    // CMS pages
    Route::get('/pages',                            [AdminPageController::class, 'index']);
    Route::post('/pages',                           [AdminPageController::class, 'store']);
    Route::get('/pages/{page}',                     [AdminPageController::class, 'show']);
    Route::patch('/pages/{page}',                   [AdminPageController::class, 'update']);
    Route::delete('/pages/{page}',                  [AdminPageController::class, 'destroy']);
    Route::post('/pages/{page}/publish',            [AdminPageController::class, 'publish']);
    Route::post('/pages/{page}/unpublish',          [AdminPageController::class, 'unpublish']);

    // MFA management (requires existing admin session)
    Route::prefix('auth/mfa')->group(function () {
        Route::get('/setup',    [AdminMfaController::class, 'setup']);
        Route::post('/confirm', [AdminMfaController::class, 'confirm']);
        Route::delete('/',      [AdminMfaController::class, 'disable']);
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
        // Search must be declared before /{noradId} to avoid being captured by the wildcard.
        Route::get('/search', SatelliteSearchController::class);

        Route::get('/{noradId}',       [SatelliteController::class, 'show'])->whereNumber('noradId');
        Route::get('/{noradId}/orbit', [SatelliteController::class, 'orbit'])->whereNumber('noradId');
    });

    Route::prefix('conjunctions')->group(function () {
        Route::get('/{noradId}', [ConjunctionController::class, 'index'])->whereNumber('noradId');
    });
});
