<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add Cashier-compatible columns to subscriptions.
        // name:        Cashier uses named subscriptions (e.g. "default").
        // stripe_id:   Cashier requires this as NOT NULL on real Stripe; nullable now for mock mode.
        // stripe_price: Stripe price ID (e.g. price_xxx) — maps our plan key → Stripe on cutover.
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('name')->default('default')->after('user_id');
            $table->string('stripe_id')->nullable()->after('name');
            $table->string('stripe_price')->nullable()->after('stripe_id');
        });

        // Add per-user add-on capability overrides.
        // Stores a JSON capability map (e.g. {"requests_per_day": 50000, "can_receive_alerts": true})
        // that is merged on top of the user's base plan entitlements in EntitlementService::forUser().
        // Minimal foundation — grows into a user_addons table when add-ons become a product feature.
        Schema::table('users', function (Blueprint $table): void {
            $table->json('addons')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['name', 'stripe_id', 'stripe_price']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('addons');
        });
    }
};
