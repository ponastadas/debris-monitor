<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit log for all privileged admin actions.
 *
 * Event naming convention:  <domain>.<verb_past_tense>
 * - Domain groups related events for easy filtering (login, user, payment, …).
 * - Past-tense verb records what happened, not what was attempted.
 * - Exception: login.failed / login.failed_inactive use "failed" as a
 *   sub-qualifier on the login domain rather than a past tense, because there
 *   is no clean past tense for "the login attempt was rejected."
 *
 * Catalogue of defined events (use the constants — never raw strings):
 *
 *   Auth
 *     LOGIN_SUCCESS          login.success
 *     LOGIN_FAILED           login.failed           wrong password or unknown email
 *     LOGIN_FAILED_INACTIVE  login.failed_inactive  correct password, account deactivated
 *     LOGOUT                 logout
 *
 *   User management
 *     IMPERSONATION_STARTED  impersonation.started
 *     USER_CREATED           user.created           admin manually created a customer account
 *     USER_UPDATED           user.updated           field changes (name, etc.)
 *     USER_SUSPENDED         user.suspended
 *     USER_ACTIVATED         user.activated
 *
 *   Billing
 *     PAYMENT_REFUNDED       payment.refunded
 *     SUBSCRIPTION_UPDATED   subscription.updated   (wired when write endpoint is added)
 *
 *   API keys
 *     API_KEY_REVOKED        api_key.revoked        (wired when admin revoke endpoint is added)
 *
 *   MFA
 *     MFA_ENABLED            mfa.enabled
 *     MFA_DISABLED           mfa.disabled
 *     MFA_CHALLENGE_PASSED   mfa.challenge_passed
 *     MFA_CHALLENGE_FAILED   mfa.challenge_failed
 *     MFA_RECOVERY_USED      mfa.recovery_used
 *
 *   Pages (CMS)
 *     PAGE_CREATED           page.created
 *     PAGE_UPDATED           page.updated
 *     PAGE_PUBLISHED         page.published
 *     PAGE_UNPUBLISHED       page.unpublished
 *     PAGE_DELETED           page.deleted
 */
class AdminAuditLog extends Model
{
    // ── Event catalogue ───────────────────────────────────────────────────────

    // Auth
    const LOGIN_SUCCESS          = 'login.success';
    const LOGIN_FAILED           = 'login.failed';
    const LOGIN_FAILED_INACTIVE  = 'login.failed_inactive';
    const LOGOUT                 = 'logout';

    // User management
    const IMPERSONATION_STARTED  = 'impersonation.started';
    const USER_CREATED           = 'user.created';
    const USER_UPDATED           = 'user.updated';
    const USER_SUSPENDED         = 'user.suspended';
    const USER_ACTIVATED         = 'user.activated';

    // Billing
    const PAYMENT_REFUNDED       = 'payment.refunded';
    const SUBSCRIPTION_UPDATED   = 'subscription.updated';

    // API keys
    const API_KEY_REVOKED        = 'api_key.revoked';

    // MFA
    const MFA_ENABLED            = 'mfa.enabled';
    const MFA_DISABLED           = 'mfa.disabled';
    const MFA_CHALLENGE_PASSED   = 'mfa.challenge_passed';
    const MFA_CHALLENGE_FAILED   = 'mfa.challenge_failed';
    const MFA_RECOVERY_USED      = 'mfa.recovery_used';

    // Pages (CMS)
    const PAGE_CREATED           = 'page.created';
    const PAGE_UPDATED           = 'page.updated';
    const PAGE_PUBLISHED         = 'page.published';
    const PAGE_UNPUBLISHED       = 'page.unpublished';
    const PAGE_DELETED           = 'page.deleted';

    // ── Model config ──────────────────────────────────────────────────────────

    protected $table = 'admin_audit_logs';

    /** Only created_at is stored; the log is immutable after insert. */
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminAccount::class, 'admin_account_id');
    }

    // ── Query scopes ──────────────────────────────────────────────────────────

    /** Filter by a single action string, e.g. AdminAuditLog::forAction(self::LOGIN_SUCCESS). */
    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /** Filter to entries written by a specific admin account. */
    public function scopeForActor(Builder $query, int $adminId): Builder
    {
        return $query->where('admin_account_id', $adminId);
    }

    /** Most-recent-first, capped at $limit rows. */
    public function scopeRecent(Builder $query, int $limit = 50): Builder
    {
        return $query->latest('created_at')->limit($limit);
    }

    // ── Recording helper ──────────────────────────────────────────────────────

    /**
     * Write a single immutable audit entry.
     *
     * @param  ?int     $adminId    Null only for pre-authentication failures (unknown email).
     * @param  string   $action     One of the class constants defined above.
     * @param  ?string  $targetType Eloquent model class shortname, e.g. 'User', 'Payment'.
     * @param  ?int     $targetId   Primary key of the target record.
     * @param  array    $metadata   Contextual data. Must NOT contain passwords, tokens, or secrets.
     */
    public static function record(
        ?int $adminId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $metadata = [],
    ): void {
        static::create([
            'admin_account_id' => $adminId,
            'action'           => $action,
            'target_type'      => $targetType,
            'target_id'        => $targetId,
            'metadata'         => $metadata ?: null,
            'ip'               => request()->ip(),
            'user_agent'       => request()->userAgent(),
        ]);
    }
}
