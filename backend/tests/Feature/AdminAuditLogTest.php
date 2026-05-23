<?php

use App\Models\AdminAccount;
use App\Models\AdminAuditLog;
use App\Models\Payment;
use App\Models\User;
use App\Services\AdminMfaService;
use PragmaRX\Google2FA\Google2FA;

// ── login.success ─────────────────────────────────────────────────────────────
// login.success is only emitted after MFA passes (credentials + TOTP step 2).
// Admins without MFA get mfa_setup_required — no session is issued.

it('records login.success after successful MFA verification', function () {
    $totp = new Google2FA;
    $secret = $totp->generateSecretKey(32);

    $mfa = new AdminMfaService;
    $admin = AdminAccount::factory()->create([
        'email' => 'audit@test.local',
        'password' => bcrypt('secret123'),
        'mfa_secret' => $secret,
        'mfa_recovery_codes' => $mfa->hashRecoveryCodes($mfa->generateRecoveryCodes()),
    ]);

    // Step 1: credentials → mfa_token
    $mfaToken = $this->postJson('/api/admin/auth/login', [
        'email' => 'audit@test.local',
        'password' => 'secret123',
    ])->assertOk()->json('data.mfa_token');

    // Step 2: TOTP → session token + login.success logged
    $this->postJson('/api/admin/auth/mfa/verify', [
        'mfa_token' => $mfaToken,
        'code' => $totp->getCurrentOtp($secret),
    ])->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::LOGIN_SUCCESS)->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->admin_account_id)->toBe($admin->id);
});

// ── login.failed ──────────────────────────────────────────────────────────────

it('records login.failed with non-null actor when the password is wrong', function () {
    AdminAccount::factory()->create([
        'email' => 'audit@test.local',
        'password' => bcrypt('secret123'),
    ]);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'audit@test.local',
        'password' => 'wrongpassword',
    ])->assertUnprocessable();

    $log = AdminAuditLog::forAction(AdminAuditLog::LOGIN_FAILED)->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['email'])->toBe('audit@test.local')
        ->and($log->admin_account_id)->not->toBeNull(); // account was found
});

it('records login.failed with null actor for an unknown email', function () {
    $this->postJson('/api/admin/auth/login', [
        'email' => 'nobody@test.local',
        'password' => 'anything',
    ])->assertUnprocessable();

    $log = AdminAuditLog::forAction(AdminAuditLog::LOGIN_FAILED)->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['email'])->toBe('nobody@test.local')
        ->and($log->admin_account_id)->toBeNull(); // no matching account
});

// ── login.failed_inactive ─────────────────────────────────────────────────────

it('records login.failed_inactive for a deactivated account', function () {
    AdminAccount::factory()->inactive()->create([
        'email' => 'inactive@test.local',
        'password' => bcrypt('secret123'),
    ]);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'inactive@test.local',
        'password' => 'secret123',
    ])->assertForbidden();

    $log = AdminAuditLog::forAction(AdminAuditLog::LOGIN_FAILED_INACTIVE)->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['email'])->toBe('inactive@test.local');
});

// ── logout ────────────────────────────────────────────────────────────────────

it('records logout when an admin logs out', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/admin/auth/logout')
        ->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::LOGOUT)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull();
});

// ── impersonation.started ─────────────────────────────────────────────────────

it('records impersonation.started when an admin impersonates a user', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;
    $target = User::factory()->create();

    $this->withToken($token)
        ->postJson("/api/admin/users/{$target->id}/impersonate")
        ->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::IMPERSONATION_STARTED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->target_type)->toBe('User')
        ->and($log->target_id)->toBe($target->id)
        ->and($log->metadata['target_email'])->toBe($target->email);
});

// ── user.suspended ────────────────────────────────────────────────────────────

it('records user.suspended when an admin suspends a user', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;
    $target = User::factory()->create(['status' => 'active']);

    $this->withToken($token)
        ->patchJson("/api/admin/users/{$target->id}", ['status' => 'suspended'])
        ->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::USER_SUSPENDED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->target_id)->toBe($target->id);
});

// ── user.activated ────────────────────────────────────────────────────────────

it('records user.activated when an admin reactivates a user', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;
    $target = User::factory()->create(['status' => 'suspended', 'suspended_at' => now()]);

    $this->withToken($token)
        ->patchJson("/api/admin/users/{$target->id}", ['status' => 'active'])
        ->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::USER_ACTIVATED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->target_id)->toBe($target->id);
});

// ── user.updated ──────────────────────────────────────────────────────────────

it('records user.updated when an admin changes only the user name', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;
    $target = User::factory()->create(['name' => 'Old Name']);

    $this->withToken($token)
        ->patchJson("/api/admin/users/{$target->id}", ['name' => 'New Name'])
        ->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::USER_UPDATED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->target_id)->toBe($target->id)
        ->and($log->metadata['fields'])->toContain('name');
});

// ── payment.refunded ──────────────────────────────────────────────────────────

it('records payment.refunded when an admin refunds a payment', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;
    $payment = Payment::factory()->create(['status' => 'succeeded', 'amount' => 2900]);

    $this->withToken($token)
        ->postJson("/api/admin/payments/{$payment->id}/refund")
        ->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::PAYMENT_REFUNDED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->target_type)->toBe('Payment')
        ->and($log->target_id)->toBe($payment->id)
        ->and($log->metadata['amount'])->toBe(2900)
        ->and($log->metadata['currency'])->toBe('usd');
});

// ── user_agent capture ────────────────────────────────────────────────────────
// LOGIN_SUCCESS is only emitted after MFA passes. We verify user_agent is
// captured on LOGIN_FAILED (no MFA required to reach that code path).

it('captures user_agent on audit entries written during the auth flow', function () {
    AdminAccount::factory()->create([
        'email' => 'ua@test.local',
        'password' => bcrypt('secret123'),
    ]);

    $this->withHeader('User-Agent', 'TestBrowser/1.0')
        ->postJson('/api/admin/auth/login', [
            'email' => 'ua@test.local',
            'password' => 'wrongpassword',
        ])->assertUnprocessable();

    $log = AdminAuditLog::forAction(AdminAuditLog::LOGIN_FAILED)->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_agent)->toBe('TestBrowser/1.0');
});

// ── Query scopes ──────────────────────────────────────────────────────────────

it('forAction scope filters entries by action', function () {
    $admin = AdminAccount::factory()->create();

    AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);
    AdminAuditLog::record($admin->id, AdminAuditLog::LOGOUT);
    AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);

    expect(AdminAuditLog::forAction(AdminAuditLog::LOGIN_SUCCESS)->count())->toBe(2)
        ->and(AdminAuditLog::forAction(AdminAuditLog::LOGOUT)->count())->toBe(1);
});

it('forActor scope filters entries by admin account', function () {
    $adminA = AdminAccount::factory()->create();
    $adminB = AdminAccount::factory()->create();

    AdminAuditLog::record($adminA->id, AdminAuditLog::LOGIN_SUCCESS);
    AdminAuditLog::record($adminA->id, AdminAuditLog::LOGOUT);
    AdminAuditLog::record($adminB->id, AdminAuditLog::LOGIN_SUCCESS);

    expect(AdminAuditLog::forActor($adminA->id)->count())->toBe(2)
        ->and(AdminAuditLog::forActor($adminB->id)->count())->toBe(1);
});

it('recent scope returns the most recent entries up to the given limit', function () {
    $admin = AdminAccount::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);
    }

    $results = AdminAuditLog::recent(3)->get();

    expect($results)->toHaveCount(3)
        ->and($results->first()->created_at->greaterThanOrEqualTo($results->last()->created_at))->toBeTrue();
});

it('scopes can be chained', function () {
    $admin = AdminAccount::factory()->create();

    AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);
    AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);
    AdminAuditLog::record($admin->id, AdminAuditLog::LOGOUT);
    AdminAuditLog::record(null, AdminAuditLog::LOGIN_FAILED, metadata: ['email' => 'x@x.com']);

    expect(
        AdminAuditLog::forAction(AdminAuditLog::LOGIN_SUCCESS)->forActor($admin->id)->count()
    )->toBe(2);
});

// ── Immutability ──────────────────────────────────────────────────────────────

it('audit log entries have no updated_at column', function () {
    $admin = AdminAccount::factory()->create();

    AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);

    $log = AdminAuditLog::forAction(AdminAuditLog::LOGIN_SUCCESS)->first();

    // $timestamps = false means only created_at is persisted
    expect($log->created_at)->not->toBeNull()
        ->and($log->getAttributes())->not->toHaveKey('updated_at');
});
