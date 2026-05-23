<?php

use App\Models\AdminAccount;
use App\Models\ApiKey;
use App\Models\GuestUsage;
use App\Models\User;

// ── Guest access ──────────────────────────────────────────────────────────────

it('allows guest access to conjunction endpoint without any credentials', function () {
    $this->withHeaders(['X-Guest-ID' => 'guest-open-access'])
        ->getJson('/api/conjunctions/25544')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.norad_id', '25544')
        ->assertJsonStructure(['success', 'data' => ['norad_id', 'object_count', 'objects']]);
});

it('increments the guest request count on each access', function () {
    $id = 'guest-count-test';

    $this->withHeaders(['X-Guest-ID' => $id])->getJson('/api/conjunctions/25544')->assertOk();
    expect(GuestUsage::todayCount($id))->toBe(1);

    $this->withHeaders(['X-Guest-ID' => $id])->getJson('/api/conjunctions/25544')->assertOk();
    expect(GuestUsage::todayCount($id))->toBe(2);
});

it('blocks guest after the daily limit is reached', function () {
    $id = 'guest-over-limit';
    GuestUsage::create(['identifier' => $id, 'date' => today(), 'count' => 10]);

    $this->withHeaders(['X-Guest-ID' => $id])
        ->getJson('/api/conjunctions/25544')
        ->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'GUEST_LIMIT_REACHED');
});

it('returns a correctly shaped limit response', function () {
    $id = 'guest-limit-shape';
    GuestUsage::create(['identifier' => $id, 'date' => today(), 'count' => 10]);

    $this->withHeaders(['X-Guest-ID' => $id])
        ->getJson('/api/conjunctions/25544')
        ->assertStatus(429)
        ->assertJsonStructure([
            'success',
            'data',
            'error' => [
                'code',
                'message',
                'details' => ['limit', 'used', 'reset_at', 'upgrade_url'],
            ],
        ])
        ->assertJsonPath('error.details.limit', 10)
        ->assertJsonPath('error.details.used', 10);
});

it('does not count the 10th request — the limit check is >=', function () {
    // At count=9, the 10th request must succeed (9 < 10), then count becomes 10
    $id = 'guest-boundary';
    GuestUsage::create(['identifier' => $id, 'date' => today(), 'count' => 9]);

    $this->withHeaders(['X-Guest-ID' => $id])->getJson('/api/conjunctions/25544')->assertOk();
    expect(GuestUsage::todayCount($id))->toBe(10);

    // The 11th is the first that should be blocked
    $this->withHeaders(['X-Guest-ID' => $id])
        ->getJson('/api/conjunctions/25544')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'GUEST_LIMIT_REACHED');
});

it('returns remaining-count and limit headers on guest requests', function () {
    $id = 'guest-headers';
    GuestUsage::create(['identifier' => $id, 'date' => today(), 'count' => 7]);

    $response = $this->withHeaders(['X-Guest-ID' => $id])
        ->getJson('/api/conjunctions/25544')
        ->assertOk();

    // After consuming request #8: 10 - 7 - 1 = 2 remaining
    expect($response->headers->get('X-Guest-Requests-Remaining'))->toBe('2');
    expect($response->headers->get('X-Guest-Limit'))->toBe('10');
});

it('falls back to IP when no X-Guest-ID header is sent', function () {
    // No X-Guest-ID — middleware will use 127.0.0.1 (test client IP)
    $this->getJson('/api/conjunctions/25544')->assertOk();

    expect(GuestUsage::todayCount('127.0.0.1'))->toBe(1);
});

// ── Authenticated user path ───────────────────────────────────────────────────

it('allows an authenticated user through without consuming any guest quota', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/conjunctions/25544')
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(GuestUsage::count())->toBe(0);
});

it('authenticated user is not subject to guest daily limit', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    // Pre-fill guest quota for the fallback IP — should have no effect on auth'd users
    GuestUsage::create(['identifier' => '127.0.0.1', 'date' => today(), 'count' => 10]);

    $this->withToken($token)
        ->getJson('/api/conjunctions/25544')
        ->assertOk();
});

// ── API key path ──────────────────────────────────────────────────────────────

it('allows a valid API key to access the conjunction endpoint', function () {
    $user = User::factory()->create();
    $key = ApiKey::factory()->create(['user_id' => $user->id, 'tier' => 'free', 'daily_limit' => 100]);

    $this->withHeaders(['X-API-Key' => $key->key])
        ->getJson('/api/conjunctions/25544')
        ->assertOk()
        ->assertHeader('X-API-Tier', 'free')
        ->assertHeader('X-RateLimit-Limit', '100');
});

it('blocks an API key that has exceeded its daily limit', function () {
    $user = User::factory()->create();
    $key = ApiKey::factory()->create(['user_id' => $user->id, 'daily_limit' => 1]);

    // First request — uses the single allowed slot
    $this->withHeaders(['X-API-Key' => $key->key])->getJson('/api/conjunctions/25544')->assertOk();

    // Second request — over limit
    $this->withHeaders(['X-API-Key' => $key->key])
        ->getJson('/api/conjunctions/25544')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
});

it('rejects an invalid API key with a clear error', function () {
    $this->withHeaders(['X-API-Key' => 'dm_live_notarealkey'])
        ->getJson('/api/conjunctions/25544')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'INVALID_API_KEY');
});

it('api key path does not consume guest quota', function () {
    $user = User::factory()->create();
    $key = ApiKey::factory()->create(['user_id' => $user->id]);

    $this->withHeaders(['X-API-Key' => $key->key])->getJson('/api/conjunctions/25544')->assertOk();

    expect(GuestUsage::count())->toBe(0);
});

// ── Auth boundaries ───────────────────────────────────────────────────────────

it('requires authentication for the alerts endpoint', function () {
    $this->getJson('/api/alerts')->assertUnauthorized();
});

it('requires authentication for api key management', function () {
    $this->getJson('/api/keys')->assertUnauthorized();
    $this->postJson('/api/keys', ['name' => 'test'])->assertUnauthorized();
});

it('requires authentication for billing endpoints', function () {
    $this->getJson('/api/billing/plan')->assertUnauthorized();
    $this->postJson('/api/billing/subscribe', ['plan' => 'starter'])->assertUnauthorized();
});

it('requires authentication for watched satellite management', function () {
    $this->getJson('/api/watch')->assertUnauthorized();
});

it('requires authentication for profile endpoints', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();
});

it('blocks a regular user token from admin endpoints', function () {
    // Customer tokens are issued against the users table (tokenable_type=User).
    // The auth:admin guard only accepts AdminAccount tokens — user tokens get 401.
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/admin/dashboard')
        ->assertUnauthorized();
});

it('blocks unauthenticated requests from admin endpoints', function () {
    $this->getJson('/api/admin/dashboard')->assertUnauthorized();
});

it('allows an admin account token to access admin endpoints', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/admin/dashboard')
        ->assertOk();
});

// ── Admin token on public endpoints ───────────────────────────────────────────

it('admin token can access public satellite endpoints without consuming guest quota', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/conjunctions/25544')
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(GuestUsage::count())->toBe(0);
});

it('admin token is not subject to guest daily limit even when quota is exhausted', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    // Pre-exhaust the guest quota for the fallback IP
    GuestUsage::create(['identifier' => '127.0.0.1', 'date' => today(), 'count' => 10]);

    $this->withToken($token)
        ->getJson('/api/conjunctions/25544')
        ->assertOk();
});

it('invalid bearer token falls through to guest path', function () {
    $id = 'guest-invalid-token-fallback';

    $this->withToken('not-a-real-token')
        ->withHeaders(['X-Guest-ID' => $id])
        ->getJson('/api/conjunctions/25544')
        ->assertOk();

    // Guest quota was consumed — invalid token was treated as unauthenticated
    expect(GuestUsage::todayCount($id))->toBe(1);
});

it('admin token is rejected by customer-only endpoints', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    // /api/auth/me requires a customer (User) Sanctum token — admin token must be rejected
    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});
