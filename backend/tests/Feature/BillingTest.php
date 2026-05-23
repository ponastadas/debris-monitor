<?php

use App\Models\ApiKey;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────────────────────

function authUser(?array $attributes = []): array
{
    $user = User::factory()->create($attributes);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token];
}

// ── Current plan ──────────────────────────────────────────────────────────────

it('returns free plan for a user with no subscription', function () {
    [$user, $token] = authUser();

    $this->withToken($token)
        ->getJson('/api/billing/plan')
        ->assertOk()
        ->assertJsonPath('data.plan', 'free')
        ->assertJsonPath('data.plan_label', 'Free')
        ->assertJsonPath('data.status', 'none');
});

it('current plan response includes entitlements and available_plans', function () {
    [$user, $token] = authUser();

    $res = $this->withToken($token)
        ->getJson('/api/billing/plan')
        ->assertOk();

    $data = $res->json('data');

    expect($data)->toHaveKeys(['entitlements', 'available_plans'])
        ->and($data['entitlements'])->toHaveKey('requests_per_day')
        ->and($data['available_plans'])->toHaveCount(3);
});

it('current plan reflects an active subscription', function () {
    [$user, $token] = authUser();
    Subscription::factory()->create(['user_id' => $user->id, 'plan' => 'pro', 'status' => 'active']);

    $this->withToken($token)
        ->getJson('/api/billing/plan')
        ->assertOk()
        ->assertJsonPath('data.plan', 'pro')
        ->assertJsonPath('data.plan_label', 'Pro')
        ->assertJsonPath('data.status', 'active');
});

// ── Subscribe ─────────────────────────────────────────────────────────────────

it('subscribe creates an active subscription and records a payment', function () {
    [$user, $token] = authUser();

    $this->withToken($token)
        ->postJson('/api/billing/subscribe', ['plan' => 'starter'])
        ->assertOk()
        ->assertJsonPath('data.plan', 'starter')
        ->assertJsonPath('data.status', 'active');

    expect(Subscription::where('user_id', $user->id)->where('plan', 'starter')->exists())->toBeTrue()
        ->and(Payment::where('user_id', $user->id)->where('amount', 2900)->exists())->toBeTrue();
});

it('subscribe response includes entitlements for the new plan', function () {
    [$user, $token] = authUser();

    $res = $this->withToken($token)
        ->postJson('/api/billing/subscribe', ['plan' => 'starter'])
        ->assertOk();

    expect($res->json('data.entitlements.requests_per_day'))->toBe(10_000)
        ->and($res->json('data.entitlements.can_receive_alerts'))->toBeTrue();
});

it('subscribe upgrades an existing subscription', function () {
    [$user, $token] = authUser();
    Subscription::factory()->create(['user_id' => $user->id, 'plan' => 'starter', 'status' => 'active']);

    $this->withToken($token)
        ->postJson('/api/billing/subscribe', ['plan' => 'pro'])
        ->assertOk()
        ->assertJsonPath('data.plan', 'pro');

    // Should still be a single subscription row (updateOrCreate)
    expect(Subscription::where('user_id', $user->id)->count())->toBe(1);
});

it('subscribe syncs api key tier to the new plan', function () {
    [$user, $token] = authUser();
    $key = ApiKey::factory()->create(['user_id' => $user->id, 'tier' => 'free', 'daily_limit' => 100]);

    $this->withToken($token)
        ->postJson('/api/billing/subscribe', ['plan' => 'starter'])
        ->assertOk();

    expect($key->fresh()->tier)->toBe('starter')
        ->and($key->fresh()->daily_limit)->toBe(10000);
});

it('subscribe rejects an invalid plan name', function () {
    [$user, $token] = authUser();

    $this->withToken($token)
        ->postJson('/api/billing/subscribe', ['plan' => 'ultra'])
        ->assertUnprocessable();
});

it('subscribe rejects the free plan (not a paid plan)', function () {
    [$user, $token] = authUser();

    $this->withToken($token)
        ->postJson('/api/billing/subscribe', ['plan' => 'free'])
        ->assertUnprocessable();
});

// ── Cancel ────────────────────────────────────────────────────────────────────

it('cancel sets status to canceled and downgrades api keys', function () {
    [$user, $token] = authUser();
    Subscription::factory()->create(['user_id' => $user->id, 'plan' => 'pro', 'status' => 'active']);
    $key = ApiKey::factory()->create(['user_id' => $user->id, 'tier' => 'pro', 'daily_limit' => 100000]);

    $this->withToken($token)
        ->postJson('/api/billing/cancel')
        ->assertOk();

    $sub = Subscription::where('user_id', $user->id)->first();
    expect($sub->status)->toBe('canceled')
        ->and($sub->canceled_at)->not->toBeNull()
        ->and($key->fresh()->tier)->toBe('free')
        ->and($key->fresh()->daily_limit)->toBe(100);
});

it('cancel returns error when there is no active subscription', function () {
    [$user, $token] = authUser();

    $this->withToken($token)
        ->postJson('/api/billing/cancel')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'NO_ACTIVE_SUBSCRIPTION');
});

it('cancel returns error when subscription is already canceled', function () {
    [$user, $token] = authUser();
    Subscription::factory()->canceled()->create(['user_id' => $user->id]);

    $this->withToken($token)
        ->postJson('/api/billing/cancel')
        ->assertUnprocessable();
});

// ── Payment history ───────────────────────────────────────────────────────────

it('payment history returns an empty list when no payments exist', function () {
    [$user, $token] = authUser();

    $this->withToken($token)
        ->getJson('/api/billing/history')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('payment history returns payments in reverse chronological order', function () {
    [$user, $token] = authUser();

    Payment::factory()->create(['user_id' => $user->id, 'amount' => 2900, 'description' => 'Starter', 'created_at' => now()->subDays(2)]);
    Payment::factory()->create(['user_id' => $user->id, 'amount' => 9900, 'description' => 'Pro',     'created_at' => now()->subDay()]);

    $res = $this->withToken($token)
        ->getJson('/api/billing/history')
        ->assertOk();

    $payments = $res->json('data');

    expect($payments)->toHaveCount(2)
        ->and($payments[0]['amount'])->toBe(9900)  // most recent first
        ->and($payments[1]['amount'])->toBe(2900);
});

it('payment history response includes formatted amount', function () {
    [$user, $token] = authUser();
    Payment::factory()->create(['user_id' => $user->id, 'amount' => 2900]);

    $res = $this->withToken($token)
        ->getJson('/api/billing/history')
        ->assertOk();

    expect($res->json('data.0.formatted'))->toBe('$29.00');
});

it('payment history is scoped to the authenticated user', function () {
    [$user,  $token] = authUser();
    [$other, $token2] = authUser();
    Payment::factory()->create(['user_id' => $other->id, 'amount' => 9900]);

    $res = $this->withToken($token)
        ->getJson('/api/billing/history')
        ->assertOk();

    expect($res->json('data'))->toHaveCount(0);
});

// ── Auth guard ────────────────────────────────────────────────────────────────

it('billing endpoints require authentication', function () {
    $this->getJson('/api/billing/plan')->assertUnauthorized();
    $this->getJson('/api/billing/history')->assertUnauthorized();
    $this->postJson('/api/billing/subscribe', ['plan' => 'starter'])->assertUnauthorized();
    $this->postJson('/api/billing/cancel')->assertUnauthorized();
});
