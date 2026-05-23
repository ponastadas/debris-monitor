<?php

use App\Models\AdminAccount;
use App\Models\AdminAuditLog;
use App\Services\AdminMfaService;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Create an active admin with no MFA configured. */
function adminWithoutMfa(): AdminAccount
{
    return AdminAccount::factory()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('Password1!'),
        'is_active' => true,
    ]);
}

/** Create an active admin with a real TOTP secret configured. */
function adminWithMfa(): array
{
    $totp = new Google2FA;
    $secret = $totp->generateSecretKey(32);

    $mfa = new AdminMfaService;
    $codes = $mfa->generateRecoveryCodes();

    $admin = AdminAccount::factory()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('Password1!'),
        'is_active' => true,
        'mfa_secret' => $secret,
        'mfa_recovery_codes' => $mfa->hashRecoveryCodes($codes),
    ]);

    return [$admin, $secret, $codes];
}

/** Generate the current TOTP code for a given secret. */
function totpCode(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

// ── Login: MFA not configured → forced setup ─────────────────────────────────
// MFA is now enforced. An admin without MFA gets mfa_setup_required, not a token.

it('returns mfa_setup_required instead of a token when MFA is not configured', function () {
    $admin = adminWithoutMfa();

    $res = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'Password1!',
    ]);

    $res->assertOk()
        ->assertJsonPath('data.mfa_setup_required', true)
        ->assertJsonStructure(['data' => ['mfa_setup_required', 'setup_token']]);

    expect($res->json('data.token'))->toBeNull();
});

// ── Login: MFA configured — step 1 returns challenge ─────────────────────────

it('returns mfa_required and mfa_token when MFA is configured', function () {
    [$admin] = adminWithMfa();

    $res = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'Password1!',
    ]);

    $res->assertOk()
        ->assertJsonPath('data.mfa_required', true)
        ->assertJsonStructure(['data' => ['mfa_required', 'mfa_token']]);

    expect($res->json('data.mfa_token'))->not->toBeNull();
    expect($res->json('data.token'))->toBeNull();
});

it('stores the admin id in cache under the mfa challenge key', function () {
    [$admin] = adminWithMfa();

    $res = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'Password1!',
    ]);

    $mfaToken = $res->json('data.mfa_token');
    $cached = Cache::get(AdminMfaService::challengeKey($mfaToken));

    expect($cached)->toBe($admin->id);
});

// ── MFA verify: valid TOTP code ───────────────────────────────────────────────

it('issues a token when a valid TOTP code is submitted', function () {
    [$admin, $secret] = adminWithMfa();

    // Get challenge token
    $mfaToken = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'Password1!',
    ])->json('data.mfa_token');

    $res = $this->postJson('/api/admin/auth/mfa/verify', [
        'mfa_token' => $mfaToken,
        'code' => totpCode($secret),
    ]);

    $res->assertOk()
        ->assertJsonStructure(['data' => ['token', 'admin']]);

    expect($res->json('data.token'))->not->toBeNull();
});

it('records mfa.challenge_passed on successful TOTP verification', function () {
    [$admin, $secret] = adminWithMfa();

    $mfaToken = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'Password1!',
    ])->json('data.mfa_token');

    $this->postJson('/api/admin/auth/mfa/verify', [
        'mfa_token' => $mfaToken,
        'code' => totpCode($secret),
    ])->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::MFA_CHALLENGE_PASSED)
        ->forActor($admin->id)
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull();
});

it('clears the cache entry after successful verification', function () {
    [$admin, $secret] = adminWithMfa();

    $mfaToken = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'Password1!',
    ])->json('data.mfa_token');

    $this->postJson('/api/admin/auth/mfa/verify', [
        'mfa_token' => $mfaToken,
        'code' => totpCode($secret),
    ])->assertOk();

    expect(Cache::get(AdminMfaService::challengeKey($mfaToken)))->toBeNull();
});

// ── MFA verify: recovery code ─────────────────────────────────────────────────

it('accepts a valid recovery code and issues a token', function () {
    [$admin, $secret, $plainCodes] = adminWithMfa();

    $mfaToken = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'Password1!',
    ])->json('data.mfa_token');

    $res = $this->postJson('/api/admin/auth/mfa/verify', [
        'mfa_token' => $mfaToken,
        'code' => $plainCodes[0],
    ]);

    $res->assertOk()
        ->assertJsonStructure(['data' => ['token']]);
});

it('records mfa.recovery_used when a recovery code is consumed', function () {
    [$admin, $secret, $plainCodes] = adminWithMfa();

    $mfaToken = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'Password1!',
    ])->json('data.mfa_token');

    $this->postJson('/api/admin/auth/mfa/verify', [
        'mfa_token' => $mfaToken,
        'code' => $plainCodes[0],
    ])->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::MFA_RECOVERY_USED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull();
});

it('removes the consumed recovery code from the stored set', function () {
    [$admin, $secret, $plainCodes] = adminWithMfa();

    $mfaToken = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'Password1!',
    ])->json('data.mfa_token');

    $this->postJson('/api/admin/auth/mfa/verify', [
        'mfa_token' => $mfaToken,
        'code' => $plainCodes[0],
    ])->assertOk();

    $remaining = $admin->fresh()->mfa_recovery_codes;
    expect(count($remaining))->toBe(7);
});

// ── MFA verify: invalid code ──────────────────────────────────────────────────

it('rejects an invalid TOTP code and records mfa.challenge_failed', function () {
    [$admin] = adminWithMfa();

    $mfaToken = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'Password1!',
    ])->json('data.mfa_token');

    $res = $this->postJson('/api/admin/auth/mfa/verify', [
        'mfa_token' => $mfaToken,
        'code' => '000000',
    ]);

    $res->assertStatus(422)
        ->assertJsonPath('error.code', 'MFA_INVALID');

    $log = AdminAuditLog::forAction(AdminAuditLog::MFA_CHALLENGE_FAILED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull();
});

it('rejects an expired or unknown mfa_token', function () {
    $this->postJson('/api/admin/auth/mfa/verify', [
        'mfa_token' => 'nonexistent-token-uuid',
        'code' => '123456',
    ])->assertStatus(401)
        ->assertJsonPath('error.code', 'MFA_TOKEN_INVALID');
});

// ── MFA setup ─────────────────────────────────────────────────────────────────

it('setup returns qr_code and secret for an authenticated admin', function () {
    $admin = adminWithoutMfa();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $res = $this->withToken($token)->getJson('/api/admin/auth/mfa/setup');

    $res->assertOk()
        ->assertJsonStructure(['data' => ['qr_code', 'secret']]);

    expect($res->json('data.secret'))->not->toBeNull()
        ->and(strlen($res->json('data.secret')))->toBeGreaterThan(20);
});

it('setup requires authentication', function () {
    $this->getJson('/api/admin/auth/mfa/setup')
        ->assertUnauthorized();
});

it('setup caches the pending secret', function () {
    $admin = adminWithoutMfa();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $this->withToken($token)->getJson('/api/admin/auth/mfa/setup')->assertOk();

    $cached = Cache::get(AdminMfaService::pendingKey($admin->id));
    expect($cached)->not->toBeNull();
});

// ── MFA confirm ───────────────────────────────────────────────────────────────

it('confirm persists the secret and returns recovery codes on valid code', function () {
    $admin = adminWithoutMfa();
    $token = $admin->createToken('admin-session')->plainTextToken;

    // Generate a pending secret manually
    $totp = new Google2FA;
    $secret = $totp->generateSecretKey(32);
    Cache::put(AdminMfaService::pendingKey($admin->id), $secret, now()->addMinutes(10));

    $res = $this->withToken($token)->postJson('/api/admin/auth/mfa/confirm', [
        'code' => totpCode($secret),
    ]);

    $res->assertOk()
        ->assertJsonStructure(['data' => ['recovery_codes']]);

    expect(count($res->json('data.recovery_codes')))->toBe(8);
    expect($admin->fresh()->hasMfa())->toBeTrue();
});

it('confirm records mfa.enabled', function () {
    $admin = adminWithoutMfa();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $totp = new Google2FA;
    $secret = $totp->generateSecretKey(32);
    Cache::put(AdminMfaService::pendingKey($admin->id), $secret, now()->addMinutes(10));

    $this->withToken($token)->postJson('/api/admin/auth/mfa/confirm', [
        'code' => totpCode($secret),
    ])->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::MFA_ENABLED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull();
});

it('confirm rejects an invalid code', function () {
    $admin = adminWithoutMfa();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $totp = new Google2FA;
    $secret = $totp->generateSecretKey(32);
    Cache::put(AdminMfaService::pendingKey($admin->id), $secret, now()->addMinutes(10));

    $this->withToken($token)->postJson('/api/admin/auth/mfa/confirm', [
        'code' => '000000',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'MFA_INVALID');

    expect($admin->fresh()->hasMfa())->toBeFalse();
});

it('confirm returns 422 when the pending secret has expired', function () {
    $admin = adminWithoutMfa();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $this->withToken($token)->postJson('/api/admin/auth/mfa/confirm', [
        'code' => '123456',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'MFA_SETUP_EXPIRED');
});

// ── MFA disable ───────────────────────────────────────────────────────────────

it('disable clears MFA on correct password', function () {
    [$admin] = adminWithMfa();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/admin/auth/mfa', [
        'password' => 'Password1!',
    ])->assertOk();

    expect($admin->fresh()->hasMfa())->toBeFalse();
    expect($admin->fresh()->mfa_recovery_codes)->toBeNull();
});

it('disable records mfa.disabled', function () {
    [$admin] = adminWithMfa();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/admin/auth/mfa', [
        'password' => 'Password1!',
    ])->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::MFA_DISABLED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull();
});

it('disable rejects an incorrect password', function () {
    [$admin] = adminWithMfa();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/admin/auth/mfa', [
        'password' => 'WrongPassword!',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_PASSWORD');

    expect($admin->fresh()->hasMfa())->toBeTrue();
});

it('disable requires authentication', function () {
    $this->deleteJson('/api/admin/auth/mfa', ['password' => 'Password1!'])
        ->assertUnauthorized();
});
