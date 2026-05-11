<?php

use App\Models\AdminAccount;
use App\Models\AdminAuditLog;

// ── Auth guard ────────────────────────────────────────────────────────────────

it('rejects unauthenticated requests', function () {
    $this->getJson('/api/admin/audit-log')->assertUnauthorized();
});

it('rejects customer tokens', function () {
    $user  = \App\Models\User::factory()->create();
    $token = $user->createToken('spa')->plainTextToken;

    $this->withToken($token)->getJson('/api/admin/audit-log')->assertUnauthorized();
});

// ── Listing ───────────────────────────────────────────────────────────────────

it('returns paginated audit log entries for an authenticated admin', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);
    AdminAuditLog::record($admin->id, AdminAuditLog::LOGOUT);

    $r = $this->withToken($token)->getJson('/api/admin/audit-log')->assertOk();

    expect($r->json('data.total'))->toBeGreaterThan(1)
        ->and($r->json('data.data'))->toBeArray()
        ->and($r->json('data.data.0'))->toHaveKeys(['id', 'admin_email', 'action', 'target_type', 'target_id', 'metadata', 'ip', 'created_at']);
});

it('includes admin email and name on each entry', function () {
    $admin = AdminAccount::factory()->create(['email' => 'inspector@test.local', 'name' => 'Inspector']);
    $token = $admin->createToken('admin-session')->plainTextToken;

    AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);

    $r = $this->withToken($token)
         ->getJson('/api/admin/audit-log?action=' . AdminAuditLog::LOGIN_SUCCESS)
         ->assertOk();

    $entry = $r->json('data.data.0');
    expect($entry['admin_email'])->toBe('inspector@test.local')
        ->and($entry['admin_name'])->toBe('Inspector');
});

it('returns null actor fields for unknown-email login failures', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    // Simulate an unknown-email failure (null actor)
    AdminAuditLog::record(null, AdminAuditLog::LOGIN_FAILED, metadata: ['email' => 'ghost@test.local']);

    $r = $this->withToken($token)
         ->getJson('/api/admin/audit-log?action=' . AdminAuditLog::LOGIN_FAILED)
         ->assertOk();

    $entry = $r->json('data.data.0');
    expect($entry['admin_id'])->toBeNull()
        ->and($entry['admin_email'])->toBeNull()
        ->and($entry['metadata']['email'])->toBe('ghost@test.local');
});

// ── Filtering ─────────────────────────────────────────────────────────────────

it('filters by action', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);
    AdminAuditLog::record($admin->id, AdminAuditLog::LOGOUT);
    AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);

    $r = $this->withToken($token)
         ->getJson('/api/admin/audit-log?action=' . AdminAuditLog::LOGIN_SUCCESS)
         ->assertOk();

    expect($r->json('data.total'))->toBe(2);
    collect($r->json('data.data'))->each(fn ($e) => expect($e['action'])->toBe(AdminAuditLog::LOGIN_SUCCESS));
});

it('filters by admin_id', function () {
    $adminA = AdminAccount::factory()->create();
    $adminB = AdminAccount::factory()->create();
    $token  = $adminA->createToken('admin-session')->plainTextToken;

    AdminAuditLog::record($adminA->id, AdminAuditLog::LOGIN_SUCCESS);
    AdminAuditLog::record($adminA->id, AdminAuditLog::LOGOUT);
    AdminAuditLog::record($adminB->id, AdminAuditLog::LOGIN_SUCCESS);

    $r = $this->withToken($token)
         ->getJson("/api/admin/audit-log?admin_id={$adminA->id}")
         ->assertOk();

    expect($r->json('data.total'))->toBe(2);
    collect($r->json('data.data'))->each(fn ($e) => expect($e['admin_id'])->toBe($adminA->id));
});

it('filters by date range', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    // Old entry
    AdminAuditLog::create([
        'admin_account_id' => $admin->id,
        'action'           => AdminAuditLog::LOGOUT,
        'created_at'       => now()->subDays(10),
    ]);

    // Recent entry
    AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);

    $from = now()->subDays(1)->toDateString();
    $to   = now()->toDateString();

    $r = $this->withToken($token)
         ->getJson("/api/admin/audit-log?from={$from}&to={$to}")
         ->assertOk();

    expect($r->json('data.total'))->toBe(1)
        ->and($r->json('data.data.0.action'))->toBe(AdminAuditLog::LOGIN_SUCCESS);
});

// ── Response shape ────────────────────────────────────────────────────────────

it('returns results newest-first', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    // Use explicit created_at timestamps so the ordering is deterministic
    // even when both records are inserted within the same second.
    AdminAuditLog::create([
        'admin_account_id' => $admin->id,
        'action'           => AdminAuditLog::LOGIN_SUCCESS,
        'created_at'       => now()->subSeconds(2),
    ]);
    AdminAuditLog::create([
        'admin_account_id' => $admin->id,
        'action'           => AdminAuditLog::LOGOUT,
        'created_at'       => now(),
    ]);

    $r     = $this->withToken($token)->getJson('/api/admin/audit-log')->assertOk();
    $items = $r->json('data.data');

    expect($items[0]['action'])->toBe(AdminAuditLog::LOGOUT)
        ->and($items[1]['action'])->toBe(AdminAuditLog::LOGIN_SUCCESS);
});
