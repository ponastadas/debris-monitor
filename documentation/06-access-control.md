# 6. Access Control & Entitlements

## 6.1 Auth Architecture

SatView has two completely independent authentication systems that share no state:

```mermaid
graph LR
    subgraph Customer["Customer Auth (Sanctum)"]
        U[users table]
        CT[personal_access_tokens\ntokenable_type = User]
        U --- CT
    end

    subgraph Admin["Admin Auth (Sanctum — separate guard)"]
        A[admin_accounts table]
        AT[personal_access_tokens\ntokenable_type = AdminAccount]
        A --- AT
    end

    subgraph Guards["Laravel Guards (config/auth.php)"]
        SG["sanctum — resolves User"]
        AG["admin — resolves AdminAccount"]
    end

    CT --> SG
    AT --> AG
```

The `auth:admin` guard is defined in `config/auth.php` with `provider: admin_accounts`. A customer token can never authenticate as an admin, and an admin token can never authenticate as a customer — the guards query different tables.

---

## 6.2 Middleware Stack

```mermaid
flowchart TD
    request([Incoming request]) --> sec[SecurityHeaders\nAppends security headers]
    sec --> route{Route match}

    route -->|/api/admin/auth/*| throttle_admin[throttle:admin-login\n3/min per IP]
    throttle_admin --> admin_ctrl[AdminAuthController]

    route -->|/api/admin/*| auth_admin["auth:admin\nValidate admin Bearer token"]
    auth_admin --> ensure_admin["EnsureIsAdmin\nBlocks inactive admins"]
    ensure_admin --> admin_ctrl2[Admin controllers]

    route -->|/api/auth/* registered| throttle_auth[throttle:registration\nor throttle:auth]
    throttle_auth --> auth_ctrl[AuthController]

    route -->|/api/auth/* authenticated| auth_sanctum["auth:sanctum\nValidate user Bearer token"]
    auth_sanctum --> user_ctrl[User controllers]

    route -->|/api/satellites, /api/conjunctions| handle_public["HandlePublicRequest\nResolves actor type"]
    handle_public --> sat_ctrl[Satellite / Conjunction controllers]
```

---

## 6.3 `HandlePublicRequest` — Actor Resolution

This middleware is the core of the public API access model. It resolves one of three actor types and attaches entitlements to the request for downstream controllers to use.

```mermaid
flowchart TD
    start([Request arrives]) --> has_bearer{Bearer token\npresent?}

    has_bearer -->|Yes| try_user["auth:sanctum → User?"]
    try_user -->|Found| set_user["actor_type = user\nentitlements = forUser(user)\nNo quota applied"]
    try_user -->|Not found| try_admin["auth:admin → AdminAccount?"]
    try_admin -->|Found| set_admin["actor_type = admin\nentitlements = forAdmin()\nNo quota applied"]
    try_admin -->|Not found| api_key_check

    has_bearer -->|No| api_key_check{X-API-Key\nor ?api_key present?}

    api_key_check -->|Yes| lookup["Lookup ApiKey\n(not deleted, not expired)"]
    lookup -->|Not found| err401["401 INVALID_API_KEY"]
    lookup -->|Expired| err401e["401 API_KEY_EXPIRED"]
    lookup -->|Valid| check_limit{daily_limit exceeded?}
    check_limit -->|Yes| err429["429 RATE_LIMIT_EXCEEDED"]
    check_limit -->|No| set_api["actor_type = api_key\nentitlements = forApiKey(key)\nRecord ApiUsage\nAppend X-RateLimit-* headers"]

    api_key_check -->|No| guest["Identify guest\nX-Guest-ID header → UUID\nfallback → IP"]
    guest --> guest_limit{>= 10 requests\ntoday?}
    guest_limit -->|Yes| err429g["429 GUEST_LIMIT_REACHED"]
    guest_limit -->|No| set_guest["actor_type = guest\nGuestUsage.record()\nAppend X-Guest-* headers"]
```

---

## 6.4 Plan Tiers & Entitlements

`EntitlementService` is the single source of truth for all plan capabilities. No capability checks are hardcoded in controllers.

### Capability Matrix

| Capability | Guest | Free | Starter | Pro | Enterprise |
|-----------|-------|------|---------|-----|-----------|
| `requests_per_day` | 10 | 500 | 10,000 | 100,000 | Unlimited |
| `can_view_nearby_objects` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `can_view_alerts` | — | — | ✓ | ✓ | ✓ |
| `can_manage_watched_satellites` | — | ✓ | ✓ | ✓ | ✓ |
| `can_receive_alerts` | — | — | ✓ | ✓ | ✓ |
| `can_use_api_keys` | — | ✓ | ✓ | ✓ | ✓ |
| `webhooks_enabled` | — | — | ✓ | ✓ | ✓ |
| `satellite_limit` | — | 5 | Unlimited | Unlimited | Unlimited |

### Pricing (current)

| Plan | Price |
|------|-------|
| Starter | $29/mo |
| Pro | $99/mo |
| Enterprise | $499/mo |

### Add-ons
The `users.addons` JSON column allows per-user capability overrides. Example: a lifetime deal user on the `free` plan could have `{"can_view_alerts": true, "can_receive_alerts": true}`. `EntitlementService::forUser()` merges these on top of the base plan.

---

## 6.5 Admin Auth — MFA Flow

Admin accounts require TOTP (Time-based One-Time Password) MFA. On first login, the admin is forced through a setup flow before a session token is issued.

```mermaid
stateDiagram-v2
    [*] --> NoSession

    NoSession --> PasswordVerified: POST /admin/auth/login\n(correct password)

    PasswordVerified --> MfaSetupRequired: account.hasMfa() = false
    PasswordVerified --> MfaVerifyRequired: account.hasMfa() = true

    MfaSetupRequired --> SetupInitiated: POST /admin/auth/mfa/setup-init\n{ setup_token }
    SetupInitiated --> SetupConfirmed: POST /admin/auth/mfa/setup-finalize\n{ setup_token, totp_code }
    SetupConfirmed --> Authenticated: Sanctum token issued

    MfaVerifyRequired --> Authenticated: POST /admin/auth/mfa/verify\n{ mfa_token, totp_code }

    Authenticated --> [*]: POST /admin/auth/logout
```

**Security properties:**
- `setup_token` is a short-lived (15 min) token issued only during the setup flow — it cannot be used to get a session
- `mfa_token` is issued for the verify step only — it has no API access
- Both tokens are single-use
- MFA secret stored encrypted (AES-256-GCM via Laravel's `encrypted` cast)
- Recovery codes: 8 one-time codes, stored encrypted, downloadable as PDF via `downloadRecoveryPdf.js`

---

## 6.6 Admin Audit Log

Every state-changing action by an admin is recorded in `admin_audit_logs`. The `AdminAccountObserver` hooks into Eloquent model events to automatically log `created`, `updated`, `deleted` actions on `AdminAccount` itself. Controllers call `AdminAuditLog::create()` explicitly for user management actions.

**Logged actions include:** `login`, `logout`, `user.suspend`, `user.unsuspend`, `user.impersonate`, `user.create`, `subscription.cancel`, `payment.refund`, `page.publish`, `page.delete`, `mfa.disable`

Each log entry captures:
- `admin_account_id` (nullable — null for system-generated entries)
- `action` string
- `subject` JSON (resource type + ID)
- `metadata` JSON (IP, user-agent, before/after values)

---

## 6.7 Guest Identification

Guests are identified by a UUID stored in `localStorage` under `dm_guest_id` and sent as `X-Guest-ID` on every API request. This is generated once at app load in `App.jsx`:

```js
if (!localStorage.getItem('dm_guest_id')) {
  localStorage.setItem('dm_guest_id', crypto.randomUUID());
}
```

If no header is present (e.g., direct API calls), the middleware falls back to the request IP address. The UUID approach is preferred because multiple users on the same NAT IP share a quota under IP-based identification.

The daily quota resets at midnight UTC (GuestUsage rows are per-date).
