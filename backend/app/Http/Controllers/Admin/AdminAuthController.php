<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAccount;
use App\Models\AdminAuditLog;
use App\Services\AdminMfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = AdminAccount::where('email', $request->email)->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            AdminAuditLog::record(
                $admin?->id,
                AdminAuditLog::LOGIN_FAILED,
                metadata: ['email' => $request->email],
            );

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $admin->isActive()) {
            AdminAuditLog::record(
                $admin->id,
                AdminAuditLog::LOGIN_FAILED_INACTIVE,
                metadata: ['email' => $request->email],
            );

            return $this->error('ACCOUNT_INACTIVE', 'This admin account has been deactivated.', 403);
        }

        // MFA is configured: require TOTP before issuing a session token.
        if ($admin->hasMfa()) {
            $mfaToken = Str::uuid()->toString();
            Cache::put(AdminMfaService::challengeKey($mfaToken), $admin->id, now()->addMinutes(5));

            return $this->success(['mfa_required' => true, 'mfa_token' => $mfaToken]);
        }

        // MFA is NOT configured: require the admin to set it up before getting a
        // session token. Issue a short-lived setup token that is only accepted by
        // setup-init and setup-finalize. No session token is issued yet.
        $setupToken = Str::uuid()->toString();
        Cache::put(AdminMfaService::setupKey($setupToken), $admin->id, now()->addMinutes(15));

        return $this->success(['mfa_setup_required' => true, 'setup_token' => $setupToken]);
    }

    /**
     * Step 2 of MFA login: validate a TOTP code (or recovery code) against
     * the challenge token returned by login() and issue the session token.
     */
    public function mfaVerify(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_token' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $cacheKey = AdminMfaService::challengeKey($request->mfa_token);
        $adminId = Cache::get($cacheKey);

        if (! $adminId) {
            return $this->error('MFA_TOKEN_INVALID', 'MFA session expired or invalid.', 401);
        }

        $admin = AdminAccount::find($adminId);

        if (! $admin || ! $admin->isActive()) {
            Cache::forget($cacheKey);

            return $this->error('ACCOUNT_INACTIVE', 'This admin account has been deactivated.', 403);
        }

        $mfaService = new AdminMfaService;

        // Try TOTP code first
        if ($mfaService->verify($admin, $request->code)) {
            Cache::forget($cacheKey);
            AdminAuditLog::record($admin->id, AdminAuditLog::MFA_CHALLENGE_PASSED);

            return $this->issueToken($admin);
        }

        // Try recovery code
        if ($mfaService->consumeRecoveryCode($admin, $request->code)) {
            Cache::forget($cacheKey);
            AdminAuditLog::record($admin->id, AdminAuditLog::MFA_RECOVERY_USED);

            return $this->issueToken($admin);
        }

        AdminAuditLog::record($admin->id, AdminAuditLog::MFA_CHALLENGE_FAILED);

        return $this->error('MFA_INVALID', 'Invalid authentication code.', 422);
    }

    /**
     * Forced-setup step 1: exchange a setup_token for a QR code + plain secret.
     * The setup_token must have been issued by login() for an admin without MFA.
     */
    public function setupInit(Request $request): JsonResponse
    {
        $request->validate(['setup_token' => ['required', 'string']]);

        [$admin] = $this->resolveSetupToken($request->setup_token);
        if (! $admin) {
            return $this->setupTokenError($request->setup_token);
        }

        $mfaService = new AdminMfaService;
        $secret = $mfaService->generateSecret();
        Cache::put(AdminMfaService::pendingKey($admin->id), $secret, now()->addMinutes(15));

        $uri = $mfaService->getQrUri($admin, $secret);
        $qr = $mfaService->generateQrBase64($uri);

        return $this->success(['qr_code' => $qr, 'secret' => $secret]);
    }

    /**
     * Forced-setup step 2: verify the TOTP code, enable MFA, issue the session token.
     * Recovery codes are returned once in plaintext — they cannot be retrieved again.
     */
    public function setupFinalize(Request $request): JsonResponse
    {
        $request->validate([
            'setup_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        [$admin, $cacheKey] = $this->resolveSetupToken($request->setup_token);
        if (! $admin) {
            return $this->setupTokenError($request->setup_token);
        }

        $mfaService = new AdminMfaService;
        $pendingKey = AdminMfaService::pendingKey($admin->id);
        $secret = Cache::get($pendingKey);

        if (! $secret) {
            return $this->error('MFA_SETUP_EXPIRED', 'MFA setup session expired. Please start again.', 422);
        }

        if (! $mfaService->verifyWithSecret($secret, $request->code)) {
            return $this->error('MFA_INVALID', 'Invalid authentication code.', 422);
        }

        $plainCodes = $mfaService->generateRecoveryCodes();
        $hashedCodes = $mfaService->hashRecoveryCodes($plainCodes);

        $admin->update([
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => $hashedCodes,
        ]);

        Cache::forget($pendingKey);
        Cache::forget($cacheKey);

        AdminAuditLog::record($admin->id, AdminAuditLog::MFA_ENABLED);

        return $this->issueToken($admin, $plainCodes);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var AdminAccount $admin */
        $admin = auth('admin')->user();

        AdminAuditLog::record($admin->id, AdminAuditLog::LOGOUT);

        $request->user('admin')->currentAccessToken()->delete();

        return $this->success(null);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($this->adminResource($request->user('admin')));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve a setup_token from cache.
     * Returns [AdminAccount, cacheKey] on success, or [null, null] if invalid/expired.
     */
    private function resolveSetupToken(string $setupToken): array
    {
        $cacheKey = AdminMfaService::setupKey($setupToken);
        $adminId = Cache::get($cacheKey);

        if (! $adminId) {
            return [null, null];
        }

        $admin = AdminAccount::find($adminId);

        if (! $admin || ! $admin->isActive()) {
            Cache::forget($cacheKey);

            return [null, null];
        }

        return [$admin, $cacheKey];
    }

    private function setupTokenError(string $setupToken): JsonResponse
    {
        // Clear in case it exists but belongs to an inactive account
        Cache::forget(AdminMfaService::setupKey($setupToken));

        return $this->error('SETUP_TOKEN_INVALID', 'Setup session expired or invalid.', 401);
    }

    /**
     * Revoke any existing admin sessions, issue a fresh token, record login.success.
     * Pass $recoveryCodes only when the token is issued immediately after MFA setup —
     * they are shown once in the response so the admin can save them.
     */
    private function issueToken(AdminAccount $admin, array $recoveryCodes = []): JsonResponse
    {
        $admin->tokens()->where('name', 'admin-session')->delete();
        $token = $admin->createToken('admin-session')->plainTextToken;
        $admin->update(['last_login_at' => now()]);
        AdminAuditLog::record($admin->id, AdminAuditLog::LOGIN_SUCCESS);

        $data = [
            'admin' => $this->adminResource($admin),
            'token' => $token,
        ];

        if ($recoveryCodes) {
            $data['recovery_codes'] = $recoveryCodes;
        }

        return $this->success($data);
    }

    private function adminResource(AdminAccount $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'is_active' => $admin->is_active,
            'mfa_enabled' => $admin->hasMfa(),
            'last_login_at' => $admin->last_login_at?->toIso8601String(),
        ];
    }
}
