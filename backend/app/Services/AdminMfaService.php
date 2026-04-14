<?php

namespace App\Services;

use App\Models\AdminAccount;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class AdminMfaService
{
    private const RECOVERY_COUNT = 8;
    private const RECOVERY_LENGTH = 5; // each half of XXXXX-XXXXX

    private Google2FA $totp;

    public function __construct()
    {
        $this->totp = new Google2FA();
    }

    // ── Secret generation ─────────────────────────────────────────────────────

    /**
     * Generate a new 32-character base32 TOTP secret.
     * Store in cache during setup; only persist once confirmed.
     */
    public function generateSecret(): string
    {
        return $this->totp->generateSecretKey(32);
    }

    // ── QR code ───────────────────────────────────────────────────────────────

    /**
     * Build the otpauth:// URI that authenticator apps scan.
     * Account label is the admin's email; issuer is the app name.
     */
    public function getQrUri(AdminAccount $admin, string $secret): string
    {
        return $this->totp->getQRCodeUrl(
            config('app.name', 'Debris Monitor'),
            $admin->email,
            $secret,
        );
    }

    /**
     * Render the otpauth:// URI to a base64-encoded SVG QR code.
     * Return value is ready for use in:  data:image/svg+xml;base64,{result}
     */
    public function generateQrBase64(string $uri): string
    {
        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(220),
                new SvgImageBackEnd(),
            )
        );

        return base64_encode($writer->writeString($uri));
    }

    // ── TOTP verification ─────────────────────────────────────────────────────

    /**
     * Verify a 6-digit TOTP code against the admin's stored secret.
     * Allows a ±1 window (30 s either side) to account for clock drift.
     * Returns false if MFA is not configured.
     */
    public function verify(AdminAccount $admin, string $code): bool
    {
        if (! $admin->hasMfa()) {
            return false;
        }

        return (bool) $this->totp->verifyKey($admin->mfa_secret, $code);
    }

    /**
     * Verify a 6-digit TOTP code against an arbitrary secret (used during setup
     * confirmation, before the secret is persisted to the admin account).
     */
    public function verifyWithSecret(string $secret, string $code): bool
    {
        return (bool) $this->totp->verifyKey($secret, $code);
    }

    // ── Recovery codes ────────────────────────────────────────────────────────

    /**
     * Generate 8 plain-text recovery codes in XXXXX-XXXXX format.
     * Return the plain codes — caller is responsible for showing them once
     * and storing the hashed versions.
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $a = strtoupper(Str::random(self::RECOVERY_LENGTH));
            $b = strtoupper(Str::random(self::RECOVERY_LENGTH));
            $codes[] = "{$a}-{$b}";
        }

        return $codes;
    }

    /**
     * Bcrypt-hash an array of plain recovery codes for storage.
     * The hashes are then encrypted at rest via the Eloquent cast.
     */
    public function hashRecoveryCodes(array $plainCodes): array
    {
        return array_map(fn (string $code) => Hash::make($code), $plainCodes);
    }

    /**
     * Attempt to consume a recovery code.
     * If found: removes it from the stored set and returns true.
     * If not found or all codes are exhausted: returns false.
     *
     * Note: recovery codes are compared case-insensitively so the admin does not
     * need to type the dash exactly — we normalize both sides before comparing.
     */
    public function consumeRecoveryCode(AdminAccount $admin, string $submitted): bool
    {
        $hashes = $admin->mfa_recovery_codes ?? [];

        // Normalize submitted code — strip dashes, uppercase
        $normalised = strtoupper(str_replace('-', '', $submitted));

        foreach ($hashes as $index => $hash) {
            if (Hash::check($normalised, $hash) || Hash::check($submitted, $hash)) {
                unset($hashes[$index]);
                $admin->update(['mfa_recovery_codes' => array_values($hashes)]);

                return true;
            }
        }

        return false;
    }

    // ── Cache key helpers ─────────────────────────────────────────────────────

    /** Cache key for a short-lived MFA challenge token (post-password, pre-OTP). */
    public static function challengeKey(string $token): string
    {
        return "admin_mfa_challenge:{$token}";
    }

    /** Cache key for a pending setup secret (post-generate, pre-confirm). */
    public static function pendingKey(int $adminId): string
    {
        return "admin_mfa_pending:{$adminId}";
    }

    /**
     * Cache key for a forced-setup token (issued on first login when MFA is not
     * yet configured; grants access only to setup-init and setup-finalize).
     */
    public static function setupKey(string $token): string
    {
        return "admin_mfa_setup:{$token}";
    }
}
