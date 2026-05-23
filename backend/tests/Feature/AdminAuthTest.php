<?php

use App\Models\AdminAccount;
use App\Models\User;
use App\Services\AdminMfaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Create an admin with a configured TOTP secret so login goes through the MFA challenge flow. */
function adminWithTotp(): array
{
    $totp = new Google2FA;
    $secret = $totp->generateSecretKey(32);

    $mfa = new AdminMfaService;
    $codes = $mfa->generateRecoveryCodes();

    $admin = AdminAccount::factory()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('secret123'),
        'is_active' => true,
        'mfa_secret' => $secret,
        'mfa_recovery_codes' => $mfa->hashRecoveryCodes($codes),
    ]);

    return [$admin, $secret];
}

// ── Login: admin without MFA → forced setup flow ──────────────────────────────

it('admin without MFA configured receives mfa_setup_required on login', function () {
    AdminAccount::factory()->create([
        'email' => 'admin@test.local',
        'password' => bcrypt('secret123'),
    ]);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'admin@test.local',
        'password' => 'secret123',
    ])
        ->assertOk()
        ->assertJsonPath('data.mfa_setup_required', true)
        ->assertJsonStructure(['data' => ['mfa_setup_required', 'setup_token']]);
});

it('admin without MFA does not receive a session token on login', function () {
    AdminAccount::factory()->create([
        'email' => 'admin@test.local',
        'password' => bcrypt('secret123'),
    ]);

    $res = $this->postJson('/api/admin/auth/login', [
        'email' => 'admin@test.local',
        'password' => 'secret123',
    ]);

    expect($res->json('data.token'))->toBeNull();
});

// ── Login: admin with MFA → challenge flow ────────────────────────────────────

it('admin with MFA configured receives mfa_required on login', function () {
    [$admin] = adminWithTotp();

    $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'secret123',
    ])
        ->assertOk()
        ->assertJsonPath('data.mfa_required', true)
        ->assertJsonStructure(['data' => ['mfa_required', 'mfa_token']]);
});

it('admin with MFA does not receive a session token on credential step alone', function () {
    [$admin] = adminWithTotp();

    $res = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'secret123',
    ]);

    expect($res->json('data.token'))->toBeNull();
});

// ── Forced setup flow ─────────────────────────────────────────────────────────

it('setup-init returns qr_code and secret for a valid setup_token', function () {
    $admin = AdminAccount::factory()->create([
        'email' => 'setup@test.local',
        'password' => bcrypt('secret123'),
    ]);

    $setupToken = Str::uuid()->toString();
    Cache::put(AdminMfaService::setupKey($setupToken), $admin->id, now()->addMinutes(15));

    $res = $this->postJson('/api/admin/auth/mfa/setup-init', [
        'setup_token' => $setupToken,
    ]);

    $res->assertOk()
        ->assertJsonStructure(['data' => ['qr_code', 'secret']]);

    expect($res->json('data.secret'))->not->toBeNull()
        ->and(strlen($res->json('data.secret')))->toBeGreaterThan(20);
});

it('setup-init rejects an invalid or expired setup_token', function () {
    $this->postJson('/api/admin/auth/mfa/setup-init', [
        'setup_token' => 'nonexistent-token',
    ])->assertStatus(401)
        ->assertJsonPath('error.code', 'SETUP_TOKEN_INVALID');
});

it('setup-finalize enables MFA and issues a session token', function () {
    $admin = AdminAccount::factory()->create([
        'email' => 'finalize@test.local',
        'password' => bcrypt('secret123'),
    ]);

    $setupToken = Str::uuid()->toString();
    Cache::put(AdminMfaService::setupKey($setupToken), $admin->id, now()->addMinutes(15));

    // Manually put a pending secret in cache (as setup-init would)
    $totp = new Google2FA;
    $secret = $totp->generateSecretKey(32);
    Cache::put(AdminMfaService::pendingKey($admin->id), $secret, now()->addMinutes(15));

    $res = $this->postJson('/api/admin/auth/mfa/setup-finalize', [
        'setup_token' => $setupToken,
        'code' => $totp->getCurrentOtp($secret),
    ]);

    $res->assertOk()
        ->assertJsonStructure(['data' => ['token', 'admin', 'recovery_codes']]);

    expect($admin->fresh()->hasMfa())->toBeTrue()
        ->and(count($res->json('data.recovery_codes')))->toBe(8);
});

it('setup-finalize rejects an invalid TOTP code', function () {
    $admin = AdminAccount::factory()->create([
        'email' => 'finalize-fail@test.local',
        'password' => bcrypt('secret123'),
    ]);

    $setupToken = Str::uuid()->toString();
    Cache::put(AdminMfaService::setupKey($setupToken), $admin->id, now()->addMinutes(15));

    $totp = new Google2FA;
    $secret = $totp->generateSecretKey(32);
    Cache::put(AdminMfaService::pendingKey($admin->id), $secret, now()->addMinutes(15));

    $this->postJson('/api/admin/auth/mfa/setup-finalize', [
        'setup_token' => $setupToken,
        'code' => '000000',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'MFA_INVALID');

    expect($admin->fresh()->hasMfa())->toBeFalse();
});

it('setup-finalize returns 422 when pending secret has expired', function () {
    $admin = AdminAccount::factory()->create([
        'email' => 'no-pending@test.local',
        'password' => bcrypt('secret123'),
    ]);

    $setupToken = Str::uuid()->toString();
    Cache::put(AdminMfaService::setupKey($setupToken), $admin->id, now()->addMinutes(15));

    // No pending secret in cache
    $this->postJson('/api/admin/auth/mfa/setup-finalize', [
        'setup_token' => $setupToken,
        'code' => '123456',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'MFA_SETUP_EXPIRED');
});

// ── Login failures ────────────────────────────────────────────────────────────

it('admin login fails with wrong password', function () {
    AdminAccount::factory()->create(['email' => 'admin@test.local']);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'admin@test.local',
        'password' => 'wrongpassword',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('admin login fails for an inactive account', function () {
    AdminAccount::factory()->inactive()->create([
        'email' => 'inactive@test.local',
        'password' => bcrypt('secret123'),
    ]);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'inactive@test.local',
        'password' => 'secret123',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
});

it('admin login requires email and password', function () {
    $this->postJson('/api/admin/auth/login', [])->assertUnprocessable();
});

// ── Token guard ───────────────────────────────────────────────────────────────

it('admin token gives access to admin endpoints', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/admin/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', $admin->email);
});

it('customer token is rejected by admin endpoints', function () {
    $user = User::factory()->create();
    $token = $user->createToken('spa')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/admin/auth/me')
        ->assertUnauthorized();
});

it('admin token cannot access customer-only endpoints', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

// ── Deactivation revokes tokens ───────────────────────────────────────────────

it('deactivating an admin immediately revokes all their tokens', function () {
    $admin = AdminAccount::factory()->create();
    $admin->createToken('admin-session');

    expect($admin->tokens()->count())->toBe(1);

    $admin->update(['is_active' => false]);

    expect($admin->tokens()->count())->toBe(0);
});

it('a deactivated admin cannot use a token issued before deactivation', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $admin->update(['is_active' => false]);

    $this->withToken($token)
        ->getJson('/api/admin/auth/me')
        ->assertUnauthorized();
});

// ── Logout ────────────────────────────────────────────────────────────────────

it('admin logout deletes the current token from the database', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    expect($admin->tokens()->count())->toBe(1);

    $this->withToken($token)
        ->postJson('/api/admin/auth/logout')
        ->assertOk();

    expect($admin->tokens()->count())->toBe(0);
});
