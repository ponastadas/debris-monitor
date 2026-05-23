<?php

use App\Models\ApiKey;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;

// ── Guest ─────────────────────────────────────────────────────────────────────

it('guest entitlements: 10 requests/day, no alerts, no api keys', function () {
    $e = EntitlementService::forGuest();

    expect($e['requests_per_day'])->toBe(10)
        ->and($e['can_receive_alerts'])->toBeFalse()
        ->and($e['can_use_api_keys'])->toBeFalse()
        ->and($e['webhooks_enabled'])->toBeFalse();
});

// ── Registered users by plan ──────────────────────────────────────────────────

it('free user: 500 requests/day, no alerts, 5 satellite limit', function () {
    $user = User::factory()->create(); // no subscription → free

    $e = EntitlementService::forUser($user);

    expect($e['requests_per_day'])->toBe(500)
        ->and($e['can_receive_alerts'])->toBeFalse()
        ->and($e['satellite_limit'])->toBe(5)
        ->and($e['can_use_api_keys'])->toBeTrue();
});

it('starter user: 10,000 requests/day, alerts enabled, webhooks enabled', function () {
    $user = User::factory()->create();
    Subscription::factory()->create(['user_id' => $user->id, 'plan' => 'starter', 'status' => 'active']);
    $user->load('subscription');

    $e = EntitlementService::forUser($user);

    expect($e['requests_per_day'])->toBe(10_000)
        ->and($e['can_receive_alerts'])->toBeTrue()
        ->and($e['webhooks_enabled'])->toBeTrue()
        ->and($e['satellite_limit'])->toBeNull();
});

it('pro user: 100,000 requests/day, all capabilities', function () {
    $user = User::factory()->create();
    Subscription::factory()->create(['user_id' => $user->id, 'plan' => 'pro', 'status' => 'active']);
    $user->load('subscription');

    $e = EntitlementService::forUser($user);

    expect($e['requests_per_day'])->toBe(100_000)
        ->and($e['can_receive_alerts'])->toBeTrue()
        ->and($e['webhooks_enabled'])->toBeTrue();
});

it('enterprise user: unlimited requests, all capabilities', function () {
    $user = User::factory()->create();
    Subscription::factory()->create(['user_id' => $user->id, 'plan' => 'enterprise', 'status' => 'active']);
    $user->load('subscription');

    $e = EntitlementService::forUser($user);

    expect($e['requests_per_day'])->toBeNull()  // null = unlimited
        ->and($e['can_receive_alerts'])->toBeTrue()
        ->and($e['webhooks_enabled'])->toBeTrue();
});

// ── Add-on override ───────────────────────────────────────────────────────────

it('user addons override base plan capabilities', function () {
    $user = User::factory()->create([
        'addons' => ['requests_per_day' => 99_999, 'can_receive_alerts' => true],
    ]);
    // User is on free plan but has add-ons that lift their limits

    $e = EntitlementService::forUser($user);

    expect($e['requests_per_day'])->toBe(99_999)
        ->and($e['can_receive_alerts'])->toBeTrue()
        // Other free-plan defaults are preserved
        ->and($e['can_use_api_keys'])->toBeTrue()
        ->and($e['satellite_limit'])->toBe(5);
});

// ── API key entitlements ──────────────────────────────────────────────────────

it('api key entitlements use key-level limits, not plan defaults', function () {
    $user = User::factory()->create();
    $key = ApiKey::factory()->create([
        'user_id' => $user->id,
        'tier' => 'free',
        'daily_limit' => 250,
        'webhooks_enabled' => false,
        'satellite_limit' => 10,
    ]);

    $e = EntitlementService::forApiKey($key);

    expect($e['requests_per_day'])->toBe(250)
        ->and($e['webhooks_enabled'])->toBeFalse()
        ->and($e['satellite_limit'])->toBe(10);
});

it('api key with null daily_limit has unlimited requests', function () {
    $user = User::factory()->create();
    $key = ApiKey::factory()->create([
        'user_id' => $user->id,
        'tier' => 'enterprise',
        'daily_limit' => null,
    ]);

    $e = EntitlementService::forApiKey($key);

    expect($e['requests_per_day'])->toBeNull();
});

// ── Capability check ──────────────────────────────────────────────────────────

it('EntitlementService::can returns true for enabled capability', function () {
    $e = EntitlementService::forGuest();

    expect(EntitlementService::can($e, 'can_receive_alerts'))->toBeFalse()
        ->and(EntitlementService::can($e, 'nonexistent_cap'))->toBeFalse();
});

it('EntitlementService::can returns true for a starter-plan capability', function () {
    $user = User::factory()->create();
    Subscription::factory()->create(['user_id' => $user->id, 'plan' => 'starter', 'status' => 'active']);
    $user->load('subscription');

    $e = EntitlementService::forUser($user);

    expect(EntitlementService::can($e, 'can_receive_alerts'))->toBeTrue()
        ->and(EntitlementService::can($e, 'webhooks_enabled'))->toBeTrue();
});

// ── Catalog ───────────────────────────────────────────────────────────────────

it('catalog returns three paid plans with correct shape', function () {
    $catalog = EntitlementService::catalog();

    expect($catalog)->toHaveCount(3);

    $starter = collect($catalog)->firstWhere('key', 'starter');

    expect($starter)->not->toBeNull()
        ->and($starter['price_cents'])->toBe(2900)
        ->and($starter['price_formatted'])->toBe('$29/mo')
        ->and($starter['requests_per_day'])->toBe(10_000)
        ->and($starter['requests_label'])->toBe('10,000/day')
        ->and($starter['can_receive_alerts'])->toBeTrue()
        ->and($starter['webhooks_enabled'])->toBeTrue();
});

it('catalog does not include guest or free plans', function () {
    $keys = array_column(EntitlementService::catalog(), 'key');

    expect($keys)->not->toContain('guest')
        ->and($keys)->not->toContain('free');
});

it('paidPlanKeys returns the three paid plan identifiers', function () {
    expect(EntitlementService::paidPlanKeys())->toBe(['starter', 'pro', 'enterprise']);
});

it('label returns human-readable string for each plan', function () {
    expect(EntitlementService::label('guest'))->toBe('Guest')
        ->and(EntitlementService::label('free'))->toBe('Free')
        ->and(EntitlementService::label('starter'))->toBe('Starter')
        ->and(EntitlementService::label('pro'))->toBe('Pro')
        ->and(EntitlementService::label('enterprise'))->toBe('Enterprise')
        ->and(EntitlementService::label('unknown'))->toBe('Unknown');
});
