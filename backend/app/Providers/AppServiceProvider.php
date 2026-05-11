<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Customer registration: 5 per minute per IP (separate from login so each
        // can be tuned independently; registration abuse is a different risk profile).
        RateLimiter::for('registration', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Customer auth endpoints (login, forgot/reset): 5 attempts per minute per IP
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Admin login: stricter — 3 attempts per minute per IP
        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // Admin MFA verification: 5 attempts per 15 minutes per IP
        RateLimiter::for('admin-mfa', function (Request $request) {
            return Limit::perMinutes(15, 5)->by($request->ip());
        });
    }
}
