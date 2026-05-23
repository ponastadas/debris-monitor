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

class AdminMfaController extends Controller
{
    public function __construct(private AdminMfaService $mfa) {}

    /**
     * Begin MFA setup: generate a pending TOTP secret, cache it for 10 minutes,
     * and return the QR code + plain secret for manual entry.
     *
     * The secret is NOT yet saved to the admin_accounts row — that happens only
     * after the admin confirms with a valid TOTP code via confirm().
     */
    public function setup(Request $request): JsonResponse
    {
        /** @var AdminAccount $admin */
        $admin = auth('admin')->user();

        $secret = $this->mfa->generateSecret();
        Cache::put(AdminMfaService::pendingKey($admin->id), $secret, now()->addMinutes(10));

        $uri = $this->mfa->getQrUri($admin, $secret);
        $qr = $this->mfa->generateQrBase64($uri);

        return $this->success([
            'qr_code' => $qr,     // use as: data:image/svg+xml;base64,{qr_code}
            'secret' => $secret, // for manual entry in authenticator apps
        ]);
    }

    /**
     * Confirm MFA setup: verify the submitted TOTP code against the pending
     * cached secret. On success, persist the secret + hashed recovery codes.
     *
     * Recovery codes are returned once in plaintext — they cannot be retrieved again.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        /** @var AdminAccount $admin */
        $admin = auth('admin')->user();

        $pendingKey = AdminMfaService::pendingKey($admin->id);
        $secret = Cache::get($pendingKey);

        if (! $secret) {
            return $this->error('MFA_SETUP_EXPIRED', 'MFA setup session expired. Please start again.', 422);
        }

        if (! $this->mfa->verifyWithSecret($secret, $request->code)) {
            return $this->error('MFA_INVALID', 'Invalid authentication code.', 422);
        }

        $plainCodes = $this->mfa->generateRecoveryCodes();
        $hashedCodes = $this->mfa->hashRecoveryCodes($plainCodes);

        $admin->update([
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => $hashedCodes,
        ]);

        Cache::forget($pendingKey);

        AdminAuditLog::record($admin->id, AdminAuditLog::MFA_ENABLED);

        return $this->success([
            'recovery_codes' => $plainCodes,
        ]);
    }

    /**
     * Disable MFA: requires the admin's current password as confirmation.
     * Clears both the TOTP secret and all recovery codes.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        /** @var AdminAccount $admin */
        $admin = auth('admin')->user();

        if (! Hash::check($request->password, $admin->password)) {
            return $this->error('INVALID_PASSWORD', 'Password is incorrect.', 422);
        }

        $admin->update([
            'mfa_secret' => null,
            'mfa_recovery_codes' => null,
        ]);

        AdminAuditLog::record($admin->id, AdminAuditLog::MFA_DISABLED);

        return $this->success(null);
    }
}
