# Debris Monitor — Project Context
> Last updated: 2026-05-03 (session 29 — responsive nav + search performance: NavBar.jsx component replaces hardcoded absolute-positioned nav; hamburger menu on mobile ≤768px; dm-root/tracker-root changed from 100vh to 100% so views fill navbar-aware flex column; mobile stacked layout heights changed from vh to % for correct proportions; SatelliteSearchController CelesTrak fallback removed (local DB only); name_normalized + designator_normalized STORED generated columns added to satellites with indexes (replaces REGEXP_REPLACE per-row scan); 5-min result cache added to search; frontend AbortController + Map cache added to performSearch; debounce 400ms→300ms; 38 backend + 44 frontend tests all pass).

---

## What This Project Is

**Debris Monitor** — a satellite conjunction risk monitoring platform. Dual purpose:
1. **CI/CD demo** for annual professional goal (show lint → test → Docker build → staging/prod deploy)
2. **Passive income SaaS** (API-first, monetized via Stripe — currently mock mode)

Real product, real pipeline, real data. Not a toy.

---

## Competitive Landscape

| Product | Audience | Gap |
|---|---|---|
| spaceaware.io (Lyteworx) | Defense / Gov | Gated, no developer API |
| SpaceAware (Riskaware) | Enterprise | Complex, expensive |
| KeepTrack.space | Hobbyists | No risk scoring, CC BY-NC, no webhooks |
| **Debris Monitor** | **Developers + Startups** | **Clean API, webhooks, freemium, commercial OK** |

---

## Tech Stack

| Layer | Tech |
|---|---|
| Frontend | React 19 + Vite + Three.js (InstancedMesh for perf) + React Router v6 |
| Backend | Laravel 13 (PHP 8.3) |
| Database | MySQL 8 |
| Auth | Laravel Sanctum (bearer tokens in localStorage) |
| Testing | Pest (backend) + Vitest (frontend) |
| Linting | Laravel Pint (backend) + ESLint (frontend) |
| Containers | Docker + Docker Compose |
| CI/CD | GitHub Actions |
| Dev environment | WSL2 (Ubuntu) + Docker Desktop + VS Code Remote |

---

## Repository Structure

```
debris-monitor/
├── .github/
│   └── workflows/
│       ├── ci.yml              # lint + test on every PR
│       └── cd.yml              # Docker build → staging/prod deploy
├── backend/                    # Laravel 13 API
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── SatelliteController.php
│   │   │   │   ├── ConjunctionController.php
│   │   │   │   ├── ApiKeyController.php
│   │   │   │   ├── AlertController.php
│   │   │   │   ├── BillingController.php
│   │   │   │   ├── WatchedSatelliteController.php
│   │   │   │   ├── Auth/AuthController.php
│   │   │   │   └── Admin/
│   │   │   │       ├── AdminAuthController.php
│   │   │   │       ├── AdminMfaController.php       # MFA setup/confirm/disable
│   │   │   │       ├── AdminDashboardController.php
│   │   │   │       ├── AdminUserController.php
│   │   │   │       ├── AdminSubscriptionController.php
│   │   │   │       ├── AdminPaymentController.php
│   │   │   │       └── AdminApiKeyController.php
│   │   │   └── Middleware/
│   │   │       ├── AuthenticateApiKey.php
│   │   │       └── EnsureIsAdmin.php  # blocks inactive admin accounts
│   │   └── Models/
│   │       ├── User.php
│   │       ├── AdminAccount.php       # separate admin_accounts table; observer auto-revokes tokens on deactivation
│   │       ├── AdminAuditLog.php      # audit_log table; AdminAuditLog::record()
│   │       ├── ApiKey.php
│   │       ├── ApiUsage.php
│   │       ├── ConjunctionAlert.php
│   │       ├── WatchedSatellite.php
│   │       ├── Subscription.php
│   │       └── Payment.php
│   ├── database/migrations/    # 10 migrations total
│   ├── routes/
│   │   ├── api.php
│   │   ├── web.php
│   │   └── console.php
│   ├── tests/Feature/
│   └── Dockerfile              # multi-stage: vendor → production → development
├── frontend/                   # React 19 + Vite
│   ├── src/
│   │   ├── main.jsx            # GA4 init, React root
│   │   ├── App.jsx             # React Router v6 route definitions
│   │   ├── pages/
│   │   │   ├── Login.jsx
│   │   │   ├── Register.jsx
│   │   │   ├── ForgotPassword.jsx
│   │   │   ├── ResetPassword.jsx
│   │   │   ├── UserDashboard.jsx
│   │   │   └── admin/
│   │   │       ├── AdminLogin.jsx       # admin-only login page
│   │   │       ├── AdminDashboard.jsx
│   │   │       ├── AdminUsers.jsx
│   │   │       ├── AdminSubscriptions.jsx
│   │   │       ├── AdminPayments.jsx
│   │   │       ├── AdminApiKeys.jsx
│   │   │       └── AdminAuditLog.jsx    # audit log viewer: filter by action/date, paginated
│   │   ├── DebrisMonitor.jsx   # full catalog globe (main view)
│   │   ├── SatelliteTracker.jsx
│   │   ├── ConjunctionAlerts.jsx
│   │   ├── components/
│   │   │   ├── ProtectedRoute.jsx
│   │   │   └── AdminRoute.jsx           # uses AdminAuthContext
│   │   ├── contexts/
│   │   │   ├── AuthContext.jsx
│   │   │   ├── AdminAuthContext.jsx     # separate admin session state
│   │   │   └── ToastContext.jsx
│   │   ├── api/
│   │   │   ├── client.js       # customer axios instance (dm_token)
│   │   │   └── adminClient.js  # admin axios instance (dm_admin_token)
│   │   └── layouts/
│   │       └── AdminLayout.jsx          # uses AdminAuthContext for logout
│   ├── Dockerfile              # multi-stage: node builder → nginx
│   ├── Dockerfile.dev          # dev only, Vite HMR
│   └── vite.config.js
├── docker-compose.yml          # production (Traefik + Let's Encrypt)
├── docker-compose.local.yml    # local dev (hot reload, mailpit)
├── docker-compose.staging.yml  # staging overrides
├── Makefile
└── CLAUDE_CONTEXT.md
```

---

## Git Branch Strategy

```
main      → production (stable, protected)
develop   → staging (integration branch) ← current branch
feat/*    → feature branches off develop
```

---

## Local Dev — How to Run

```bash
# First time only
make setup    # starts MySQL, generates APP_KEY, runs migrations

# Every day
make up       # starts backend + frontend + db + mailpit

# URLs
# http://localhost:5173   → React globe UI
# http://localhost:8000   → Laravel API
# http://localhost:8000/api/health → health check
# http://localhost:8025   → Mailpit (catches emails)
# http://localhost:3306   → MySQL (TablePlus/DBeaver)
```

---

## Makefile Commands

```bash
make up               # start all services with build
make up-d             # start in background
make down             # stop
make reset            # stop + wipe DB volumes
make setup            # first-time setup (generates key + migrates)
make test             # run Pest tests
make lint             # run Pint + ESLint
make logs             # tail all logs
make logs-backend     # backend logs only
make shell            # bash into backend container
make shell-db         # mysql shell
make artisan cmd="..."    # run artisan via Docker
make sync-catalog         # fetch CelesTrak TLE data into local satellites+tle_records tables
make sync-conjunctions    # fetch real CDM data from Space-Track (requires SPACE_TRACK_USER/PASS in .env)
make seed-conjunctions    # seed demo CDM events without credentials (ISS/Hubble/GOES-16)
```

---

## API Routes

### Public
```
GET  /api/health
POST /api/auth/register
POST /api/auth/login
POST /api/auth/forgot-password
POST /api/auth/reset-password
```

### Sanctum bearer token (Authorization: Bearer <token>)
```
POST   /api/auth/logout
GET    /api/auth/me
PATCH  /api/auth/me
PATCH  /api/auth/password

GET    /api/billing/plan
GET    /api/billing/history
POST   /api/billing/subscribe
POST   /api/billing/cancel

GET    /api/keys
POST   /api/keys
DELETE /api/keys/{id}

GET    /api/watch
POST   /api/watch
DELETE /api/watch/{id}

GET    /api/alerts
```

### Public CMS pages (no auth, no rate limit)
```
GET /api/pages           → list published pages (title, slug, excerpt)
GET /api/pages/{slug}    → single published page (404 if draft)
```

### Admin auth (public — throttle: 3/min per IP)
```
POST   /api/admin/auth/login
POST   /api/admin/auth/mfa/setup-init      {setup_token}         → {qr_code, secret}
POST   /api/admin/auth/mfa/setup-finalize  {setup_token, code}   → {token, admin, recovery_codes}
```

### Admin MFA verify (public — throttle: 5/15min per IP)
```
POST   /api/admin/auth/mfa/verify   {mfa_token, code}
```

### Admin protected (admin Sanctum token — auth:admin guard)
```
POST   /api/admin/auth/logout
GET    /api/admin/auth/me

GET    /api/admin/auth/mfa/setup    → {qr_code (base64 SVG), secret}
POST   /api/admin/auth/mfa/confirm  {code} → {recovery_codes}
DELETE /api/admin/auth/mfa          {password}

GET    /api/admin/dashboard
GET    /api/admin/users
GET    /api/admin/users/{id}
PATCH  /api/admin/users/{id}
POST   /api/admin/users/{id}/impersonate
GET    /api/admin/subscriptions
GET    /api/admin/payments
POST   /api/admin/payments/{id}/refund
GET    /api/admin/api-keys
GET    /api/admin/audit-log   ?action=&admin_id=&from=&to=&page=

GET    /api/admin/pages
POST   /api/admin/pages
GET    /api/admin/pages/{page}
PATCH  /api/admin/pages/{page}
DELETE /api/admin/pages/{page}
POST   /api/admin/pages/{page}/publish
POST   /api/admin/pages/{page}/unpublish
```

### Satellite/conjunction — HandlePublicRequest (guest · Sanctum user · API key)
```
GET  /api/satellites/{noradId}        → TLE + metadata
GET  /api/satellites/{noradId}/orbit  → orbital path data
GET  /api/conjunctions/{noradId}      → nearby objects + risk scores
```

These routes accept all three actor types (priority order):
1. Bearer token → Sanctum user → unlimited web requests
2. X-API-Key header or ?api_key= → API key tier limits
3. No auth → guest (10 analyses/day, tracked by X-Guest-ID UUID or IP fallback)

**Guest rate limit headers:**
```
X-Guest-Limit: 10
X-Guest-Requests-Remaining: 7
```

**API key rate limit headers:**
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1712188799
X-API-Tier: free
```

**Guest limit response (429):**
```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "GUEST_LIMIT_REACHED",
    "message": "You've used your 10 free analyses today. Create a free account to continue.",
    "details": { "limit": 10, "used": 10, "reset_at": "...", "upgrade_url": "/register" }
  }
}
```

---

## API Response Envelope

All backend responses use a consistent envelope:
```json
{ "success": true, "data": { ... } }
{ "success": false, "error": { "type": "VALIDATION", "code": 422, "message": "...", "details": {} } }
```

Error types: `VALIDATION`, `FORBIDDEN`, `UNAUTHORIZED`, `RATE_LIMIT`, `NOT_FOUND`, `SERVER_ERROR`

Defined via Controller helpers + exception renderer overrides in `bootstrap/app.php`.

---

## Auth Architecture

### Customer Auth
- Normal sessions: Sanctum bearer tokens in `localStorage` (key: `dm_token`)
- Impersonation sessions: token delivered in JSON body by admin endpoint (never in URL), stored in `localStorage('dm_impersonate_pending')` by AdminUsers, immediately consumed by `ImpersonationHandler` into `sessionStorage('dm_token')` — tab-scoped, expires with the tab
- `client.js` request interceptor reads `sessionStorage('dm_token') || localStorage('dm_token')`; on 401 clears both
- `AuthContext` on mount: checks sessionStorage first, then localStorage; logout clears both
- Impersonation token: server-issued with 1-hour expiry (Sanctum `createToken(..., now()->addHour())`)
- Token flow: login → `personal_access_tokens` (tokenable_type=User) → Authorization header
- `AuthContext` restores session on mount; `useAuth()` exposes `{ user, loading, login, register, logout, refreshUser }`
- Axios client: `src/api/client.js` — attaches `dm_token` (session or impersonation) OR `X-Guest-ID` (mutually exclusive)
- Response 401: clears sessionStorage + localStorage token, redirects to `/login`

### Admin Auth (SEPARATE — fully isolated from customer auth)
- Admins live in `admin_accounts` table — NOT in `users` table
- `sanctum` guard explicitly configured with `provider: users` — AdminAccount tokens are rejected with 401 (not 500)
- `admin` guard configured with `provider: admin_accounts` — only accepts tokens where `tokenable_type = AdminAccount`
- Admin tokens stored in `localStorage` (key: `dm_admin_token`)
- Token flow: `/api/admin/auth/login` → `personal_access_tokens` (tokenable_type=AdminAccount) → Authorization header
- `AdminAuthContext.jsx` manages admin session; `useAdminAuth()` exposes `{ admin, loading, login, logout }`
- Separate axios client: `src/api/adminClient.js` — attaches `dm_admin_token`, 401 redirects to `/admin/login`
- Route protection: `auth:admin` middleware (built-in Laravel auth) + `admin` alias (`EnsureIsAdmin` — blocks `is_active=false`)
- Rate limiting: 3 login attempts/min per IP (`throttle:admin-login`); MFA verify: 5/15min (`throttle:admin-mfa`)
- Audit logging: every privileged action written to `admin_audit_logs` via `AdminAuditLog::record()`
  - Event catalog: constants on `AdminAuditLog` — never ad-hoc strings (LOGIN_SUCCESS, LOGIN_FAILED, LOGIN_FAILED_INACTIVE, LOGOUT, IMPERSONATION_STARTED, USER_UPDATED, USER_SUSPENDED, USER_ACTIVATED, PAYMENT_REFUNDED, SUBSCRIPTION_UPDATED, API_KEY_REVOKED, MFA_ENABLED, MFA_DISABLED, MFA_CHALLENGE_PASSED, MFA_CHALLENGE_FAILED, MFA_RECOVERY_USED)
  - Schema: id, admin_account_id (nullable FK), action, target_type, target_id, metadata (JSON), ip, user_agent, created_at (immutable)
  - `admin_account_id` nullable — allows recording login failures with unknown email (null actor)
  - Query scopes: `forAction(string)`, `forActor(int)`, `recent(int $limit = 50)` — chainable
- Token revocation: `AdminAccountObserver::updating()` deletes all tokens immediately when `is_active` flips to `false`
- MFA (TOTP — pragmarx/google2fa v9 + bacon/bacon-qr-code v3.1):
  - `AdminAccount.hasMfa()` — checks mfa_secret; `mfa_secret` uses `encrypted` cast; `mfa_recovery_codes` uses `encrypted:array` cast
  - Login: step 1 = credentials; if MFA configured → returns `{mfa_required: true, mfa_token: uuid}` (challenge stored in DB cache, 5min TTL); step 2 = POST /admin/auth/mfa/verify with `{mfa_token, code}` → issues session token
  - Recovery codes: 8 × `XXXXX-XXXXX`, bcrypt-hashed for storage, normalized before comparison; consuming one removes it from the stored set
  - `AdminMfaService` — generateSecret(), getQrUri(), generateQrBase64(), verify(), verifyWithSecret(), generateRecoveryCodes(), hashRecoveryCodes(), consumeRecoveryCode(), challengeKey(), pendingKey()
  - `AdminMfaController`: GET /admin/auth/mfa/setup (generate pending secret → QR + plain secret), POST /admin/auth/mfa/confirm (verify code → persist + return recovery codes once), DELETE /admin/auth/mfa (disable, requires password)
  - **MFA is enforced**: login never issues a session token until MFA passes; admin without MFA configured gets `{mfa_setup_required: true, setup_token: uuid}` (15min TTL) — must enroll before gaining access
  - Forced setup routes (public, throttle:admin-login): `POST /admin/auth/mfa/setup-init {setup_token}` → `{qr_code, secret}`; `POST /admin/auth/mfa/setup-finalize {setup_token, code}` → `{token, admin, recovery_codes}`
  - `AdminAuthContext` exposes: login(), verifyMfa(), setupMfaInit(), setupMfaFinalize(), completeLogin()
  - `AdminLogin.jsx`: 4-step form — credentials → MFA verify (or recovery code) → forced setup (QR + TOTP scan) → recovery codes display
  - `AdminAccount.jsx`: MFA setup/disable page at /admin/account (for already-authenticated admins)

### Cookie Consent
- `CookieConsentContext.jsx` wraps entire app (inside BrowserRouter)
- Consent stored in `localStorage('dm_cookie_consent')` as JSON: `{ v: 1, necessary: true, analytics: bool, marketing: bool, ts: ISO }`
- Version field (`v`) allows re-requesting consent on schema changes — increment `CONSENT_VERSION` constant
- `CookieBanner.jsx` shows on first visit; three actions: Accept All / Reject Non-Essential / Customize
- Customize opens `SettingsModal` with per-category checkboxes (necessary locked, analytics + marketing togglable)
- GA4 only initialized when `consent.analytics === true` (lazy import in CookieConsentContext); `main.jsx` no longer initializes GA4 at startup
- `RouteTracker` in App.jsx reads consent from localStorage before sending pageview events
- Footer has "Cookie Settings" link that re-opens the settings modal via `openSettings()` from context
- Marketing category is scaffolded (always off by default, no cookies currently use it)

### Page CMS
- `pages` table: title, slug (unique), excerpt, content (longText/Markdown), status (draft/published), meta_title, meta_description, published_at
- Slugs: auto-generated from title via `Str::slug()`; uniqueness enforced with `-2`, `-3` suffix counter
- `AdminPageController`: full CRUD + publish/unpublish; all actions audit logged
- Public `PageController`: only returns published pages; drafts return 404
- Content: stored as Markdown, rendered on frontend with `marked` + `DOMPurify` (sanitized HTML)
- `StorePageRequest` + `UpdatePageRequest`: slug validated with `[a-z0-9-]+` regex; unique constraint aware of current record on update
- `PageSeeder`: creates 5 placeholder pages (privacy-policy, cookie-policy, terms, about, contact) with real placeholder content
- Frontend: `Page.jsx` renders public pages with custom CSS prose styles; `AdminPages.jsx` list; `AdminPageEdit.jsx` form with slug auto-generation
- SEO fields (meta_title, meta_description) hidden under a `<details>` toggle in edit form

### Guest Sessions
- UUID stored in `localStorage` (key: `dm_guest_id`), sent as `X-Guest-ID` header
- Tracked via `guest_usage` table; 10 analyses/day limit

### API Key Auth
- Handled inside `HandlePublicRequest` middleware
- `X-API-Key` header or `?api_key=` query param; tier-based rate limits

---

## Frontend Routes (React Router v6)

```
/login                → Login
/register             → Register
/forgot-password      → ForgotPassword
/reset-password       → ResetPassword

(public — no auth required:)
/                     → MainApp (catalog/tracker/alerts switcher)
                          CATALOG + TRACKER: always visible
                          ALERTS tab: shows AlertsAuthGate for unauthenticated users

(ProtectedRoute — requires customer auth token:)
/dashboard            → UserDashboard (API keys, billing, watched sats)

(public admin login:)
/admin/login          → AdminLogin (posts to /api/admin/auth/login)

(AdminRoute — requires admin token in dm_admin_token:)
/admin                → AdminDashboard
/admin/users          → AdminUsers
/admin/subscriptions  → AdminSubscriptions
/admin/payments       → AdminPayments
/admin/api-keys       → AdminApiKeys
/admin/audit-log      → AdminAuditLog
/admin/account        → AdminAccount (MFA setup/disable + sign out)
/admin/users/:id      → AdminUserDetail (full profile + API keys + edit + impersonate)
/admin/pages          → AdminPages (list with publish/unpublish/delete)
/admin/pages/new      → AdminPageEdit (create)
/admin/pages/:id/edit → AdminPageEdit (edit)

(public — no auth:)
/pages/:slug          → Page (public CMS page renderer — Markdown with DOMPurify sanitization)
```

---

## Frontend Environment Variables

```
VITE_GA_MEASUREMENT_ID=G-...          # Google Analytics 4 (blank until ready)
BACKEND_URL=http://backend:8000       # Docker-only, for Vite proxy (not exposed to browser)
```

- GA4 initialized lazily in `CookieConsentContext` via dynamic `import('react-ga4')` only when `consent.analytics === true`; `main.jsx` does NOT initialize GA4
- Vite proxy: `/api` → `process.env.BACKEND_URL ?? 'http://localhost:8000'`

---

## Models & Relationships

| Model | Key relationships |
|---|---|
| `User` | hasMany ApiKey, hasMany WatchedSatellite, hasOne Subscription, hasMany Payment; `currentPlan()` |
| `AdminAccount` | HasApiTokens (Sanctum admin guard); `isActive()`. **Table**: `admin_accounts` |
| `AdminAuditLog` | belongsTo AdminAccount; `record(adminId, action, targetType?, targetId?, metadata[])` static. **Table**: `admin_audit_logs` |
| `ApiKey` | belongsTo User, hasMany ApiUsage; `tierDefaults()` static. **Table**: `api_keys` |
| `ApiUsage` | belongsTo ApiKey. **Table**: `api_usage` (explicit — Eloquent would default to `api_usages`) |
| `GuestUsage` | no relationships; `todayCount(identifier)`, `record(identifier)` static helpers. **Table**: `guest_usage` (explicit) |
| `Subscription` | belongsTo User; `isActive()`. Cashier-compat columns: name, stripe_id, stripe_price |
| `Payment` | belongsTo User; `formattedAmount()` |
| `Page` | no relationships; `scopePublished()`. **Table**: `pages` |
| `ConjunctionAlert` | scopes: `upcoming()`, `unnotified()`; methods: `riskLevel()`, `hoursUntilTca()`; columns: `source` (sgp4/space_track_cdm), `conjunction_event_id` (nullable FK) |
| `ConjunctionEvent` | raw CDM ingest; scopes: `active()` (TCA ±24h−7d), `forObject(noradId)`; methods: `riskScore()`, `riskLevel()` — PC-primary, distance-secondary |
| `WatchedSatellite` | belongsTo User; stores NORAD ID + cached TLE |
| `Satellite` | hasMany TleRecord; currentTle() → ofMany(max fetched_at, is_current=true); norad_id unique index |
| `TleRecord` | belongsTo Satellite; is_current=true is the live record; old records kept (is_current=false) |
| `Subscription` | belongsTo User; plan + billing dates |
| `Payment` | belongsTo User; payment records |

---

## Database Schema

| Table | Key columns |
|---|---|
| `users` | id, name, email, password, addons (JSON, nullable), status, suspended_at |
| `admin_accounts` | id, name, email, password, is_active (bool), mfa_secret (text, nullable, encrypted), mfa_recovery_codes (text, nullable, encrypted:array), last_login_at |
| `admin_audit_logs` | id, admin_account_id (nullable FK — null for unknown-email failures), action, target_type, target_id, metadata (JSON), ip, user_agent, created_at (immutable — no updated_at) |
| `api_keys` | id, user_id, name, key (unique), tier, daily_limit, webhooks_enabled, satellite_limit, last_used_at, expires_at, soft_deletes |
| `api_usage` | id, api_key_id, endpoint, method, status_code, response_ms, ip, created_at (no updated_at) |
| `guest_usage` | id, identifier (guest UUID or IP), date, count; unique(identifier, date) |
| `watched_satellites` | id, user_id, norad_id (indexed), name, tle_line1, tle_line2, tle_fetched_at |
| `conjunction_alerts` | id, primary_norad_id, primary_name, secondary_norad_id, secondary_name, tca, miss_distance_km, probability, risk_score, source (sgp4/space_track_cdm/null), conjunction_event_id (nullable FK → conjunction_events), notified_at |
| `conjunction_events` | id, cdm_id (unique), created_at_cdm, tca (indexed), min_range_km, probability, emergency_reportable, sat1_norad_id (indexed), sat1_name, sat2_norad_id (indexed), sat2_name, source, fetched_at, timestamps |
| `subscriptions` | id, user_id, name (default 'default'), stripe_id (nullable), stripe_price (nullable), plan, status, current_period_start, current_period_end, canceled_at |
| `payments` | id, user_id, amount (cents), currency, status, description, stripe_charge_id (nullable), refunded_at |
| `pages` | id, title, slug (unique), excerpt, content (longText), status (draft/published), meta_title, meta_description, published_at, timestamps |
| `satellites` | id, norad_id (unique indexed), name (indexed), object_type (satellite/debris/rocket_body/unknown, nullable), international_designator, country_code, launch_date, decay_date, is_active (bool), catalog_source, last_seen_at, timestamps |
| `tle_records` | id, satellite_id (FK → satellites), line1, line2, epoch_at (nullable datetime), source, fetched_at, is_current (bool), timestamps; index(satellite_id, is_current) |
| `personal_access_tokens` | (Sanctum) |

---

## Entitlement Model

**Single source of truth**: `app/Services/EntitlementService.php`

All actor types (guest / registered user / API key) resolve through `EntitlementService` to the same capability shape. No scattered `if plan === 'starter'` checks.

| Plan | req/day | can_view_nearby_objects | can_view_alerts | can_manage_watched_sats | can_receive_alerts | API keys | Webhooks | Sat limit |
|---|---|---|---|---|---|---|---|---|
| guest | 10 | ✓ (rate-limited) | ✗ | ✗ | ✗ | ✗ | ✗ | — |
| free | 500 | ✓ | ✗ | ✓ | ✗ | ✓ | ✗ | 5 |
| starter | 10k | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| pro | 100k | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| enterprise | ∞ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |

**Gating:** `can_view_alerts` gates both the frontend Alerts tab and `GET /api/alerts` (403 ALERTS_NOT_AVAILABLE if not entitled). Tracker (nearby-object analysis) is available to all including guests. `can_view_alerts` is included in `/api/auth/me` response so frontend gates without an extra API call.

**Frontend Alerts tab behavior:** guest → AlertsAuthGate (sign in / register), free user → AlertsUpgradeGate (upgrade CTA), paid → ConjunctionAlerts component. Auth loading state suppresses the gate to avoid flicker.

Methods: `forGuest()`, `forUser(User)`, `forApiKey(ApiKey)`, `can(array, string)`, `label(string)`, `catalog()`, `paidPlanKeys()`, `priceCents(string)`

`EntitlementService` also owns pricing and display labels — `BillingController` sources everything from it.

**Add-ons**: `users.addons` (JSON column) stores per-user capability overrides merged on top of base plan in `forUser()`. Example: `{"requests_per_day": 50000, "can_receive_alerts": true}`. Minimal foundation — grows into a `user_addons` table when add-ons become a product feature.

**When adding new features**: add a flag to `EntitlementService::$plans`, check it where needed.
**When adding Stripe**: map Cashier plan names to the existing plan keys — nothing else changes.
**ApiKey::tierDefaults()** on the `ApiKey` model is supplemental (key-level overrides). `EntitlementService` is authoritative for plan-level capabilities.

**Billing**: Mock mode. `BillingController` simulates subscribe/cancel/paymentHistory. DB schema mirrors what Laravel Cashier expects (name, stripe_id, stripe_price columns on subscriptions).
**Cashier cutover**: `composer require laravel/cashier`, swap the 3 BillingController mock blocks for Cashier calls. Payment records then come from Stripe webhooks instead of being manually created.

---

## Frontend Globe Architecture

### DebrisMonitor.jsx (main view)
Full catalog — all ~10,000+ tracked objects. `THREE.InstancedMesh` renders everything in one WebGL draw call (60 FPS).

Categories:
- Active satellites `#388bfd`
- Debris `#f85149`
- Rocket bodies `#d29922`
- Unknown `#8b949e`

Features: category toggles, time simulation (1×/10×/1min/s/10min/s), FPS counter, search, detail panel.

### SatelliteTracker.jsx
Single satellite: enter NORAD ID → live position + 90-min orbital path + nearby debris risk.
Data flow: TLE from internal API (`/api/satellites/{id}`, local DB → CelesTrak fallback) → SGP4 via satellite.js → client-side propagation. No direct browser → CelesTrak calls.

3D representation:
- Tracked satellite: `THREE.Sprite` with canvas-drawn billboard (solar panel silhouette + glow, per-satellite color). `createSatelliteTexture(colorHex)` draws on a 64×64 canvas, returns a `THREE.CanvasTexture`. Sprite always faces camera (billboard behavior built into SpriteMaterial). Scale 0.12 scene units.
- Ring halo: `THREE.RingGeometry` at same position, `lookAt(0,0,0)` for orbital plane orientation.
- Orbit path: 90-min arc from `THREE.Line`.
- Debris cloud: after conjunction fetch, `THREE.Points` scattered around the satellite's Earth-local position. Each conjunction object becomes 3–5 color-coded points (red=HIGH, orange=MEDIUM, green=LOW). Visual radius log-scaled from miss distance (capped at 0.18). Points added to `earthRef` so they rotate with the globe. Static once created (miss distances don't change rapidly).

Panel sections:
- "Nearby Risky Objects" section (replaces "Tracked Objects") shows loading spinner while fetch is in progress, "NO THREATS DETECTED" empty state when fetch completes with 0 results, or live conjunction list with MISS DIST / COL. PROB / TCA labels.
- `conjunctionsLoading` state prevents premature empty state flash.

### ConjunctionAlerts.jsx
User's upcoming conjunction alerts for their watched satellites.

Watch-list UX:
- `SatelliteSearchPicker` — two-tier search: instant filter against `LOCAL_CATALOG` (20 well-known satellites, no network), then 350ms debounce → `GET /api/satellites/search?q=` (CelesTrak proxy). Remote results merged with local; remote failure is silent when local matches exist.
- Keyboard navigation: ↑↓ moves active index, Enter selects, Escape closes; active item scrolls into view via itemRefs.
- Clear button (×) inside input, only shown when query non-empty; spinner shown right of clear during remote fetch.
- Quick-pick buttons: ISS, Hubble, GOES-16, Tiangong-1, Tianhe — disabled (green "✓ label") when already watched; CSS transitions, no JS hover state.
- `WatchedSatRow`: hover highlight + two-step delete confirm (✕ → REMOVE/CANCEL). `tle_fresh` display removed — `tle_fetched_at` is never written by `store()` so it was always stale/misleading.
- Empty states: "NO SATELLITES MONITORED" with radar icon, "ALL CLEAR" with green checkmark, loading shimmer (3 placeholder cards).

---

## Admin Panel

- Dark theme matching globe app (Orbitron + JetBrains Mono, `#0d1117`, `#00d4ff`)
- `AdminLayout.jsx` wraps all admin pages; uses `useAdminAuth()` for logout/identity display
- `AdminRoute` checks `AdminAuthContext` admin token (NOT user role)
- Login: `/admin/login` → `POST /api/admin/auth/login` → stores `dm_admin_token`
- Pages: dashboard stats, user management (suspend/activate only — role editing removed), subscription list, payment list + refund, API key overview, audit log viewer, account (MFA management)
- All admin pages use `adminClient.js` (not `client.js`) to send `dm_admin_token`
- Audit log written for: login.success, login.failed, login.failed_inactive, logout, impersonation.started, user.created, user.updated, user.suspended, user.activated, payment.refunded, mfa.enabled, mfa.disabled, mfa.challenge_passed, mfa.challenge_failed, mfa.recovery_used, page.created, page.updated, page.published, page.unpublished, page.deleted
- User management: edit modal now includes `name` field (status was already there); VIEW button links to `/admin/users/:id` detail page
- Protected fields: `email` and `password` are never editable by admin (explained in UI); `addons` JSON not exposed (follow-up item)
- User creation: POST /admin/users (admin-only) — creates a customer account (not admin); validates name/email/password/status; logs user.created audit event; email uniqueness enforced; password hashed via User model cast; plaintext password never logged

---

## Key Middleware

### HandlePublicRequest (`app/Http/Middleware/HandlePublicRequest.php`)
Used on `/api/satellites/*` and `/api/conjunctions/*`. Resolves actor in priority order:
1. Bearer token → `auth('sanctum')->user()` → sets actor_type=user, unlimited access
2. `X-API-Key` or `?api_key=` → validates key, checks daily_limit, logs to api_usage, adds rate limit headers
3. No auth → reads `X-Guest-ID` header (or falls back to IP), checks/increments `guest_usage` table, adds `X-Guest-Requests-Remaining` header. Returns 429 `GUEST_LIMIT_REACHED` when limit hit.

Sets request attributes: `actor_type`, `actor`, `entitlements`

### EnsureIsAdmin
Checks `auth('admin')->user()?->isActive()`. Applied AFTER `auth:admin` (which verifies the token). Blocks `is_active=false` admin accounts with 403. Admin routes use middleware stack: `['auth:admin', 'admin']`.

### SecurityHeaders (global — `bootstrap/app.php` `$middleware->append()`)
Runs on every response. Sets:
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`

### Rate limiters (AppServiceProvider)
- `admin-login`: 3/min per IP — covers login + forced-setup routes
- `admin-mfa`: 5 attempts per 15 min per IP — covers MFA verify
- `auth`: 10/min per IP — covers login + password routes
- `registration`: 5/min per IP — covers register only (separate from `auth`)

---

## Backend Controllers

### AuthController
register, login (returns bearer token), logout, me (get/update), password change, forgot-password, reset-password

### SatelliteController
- `show($noradId)` — check local DB (Satellite + TleRecord); fallback to CelesTrak only if missing/stale (>6h); caches result on fallback. Response: `{success, data: {norad_id, name, tle_line1, tle_line2, source, fetched_at}}`
- `orbit($noradId)` — same local-first logic; returns just `{norad_id, tle_line1, tle_line2}`

### SatelliteSearchController
- `GET /api/satellites/search?q=` — searches local `satellites` table by NORAD ID or name (LIKE). No live CelesTrak call. Returns `{success, data: [{norad_id, name}]}`. Catalog must be populated via `satellites:sync`.

### CatalogController
- `GET /api/catalog` — public, no auth, no rate limit. Returns all satellites with current TLE: `{success, data: {satellites: [{name, type, line1, line2}], count, synced_at}}`. `norad_id` not returned (frontend extracts from line1[2:7]). Cache-Control: max-age=3600, ETag on every response (md5 of max fetched_at). 304 returned when If-None-Match matches. Supports `?types=satellite,debris,rocket` filter (unknown tokens → no rows). Returns empty array when catalog not synced.

### ConjunctionController
- `index($noradId)` — queries `conjunction_events` for real CDM data first (scope: active() ±24h past to +7d, forObject(noradId)); falls back to simulated when no CDM data. Response `source` field: 'space_track_cdm' | 'simulated'. Frontend badge renders honestly based on source.

### ApiKeyController
- `index()` — list keys with today's usage
- `store()` — generate key (shown once), assign free tier defaults
- `destroy($id)` — soft delete (revoke)

### AlertController
- `index()` — upcoming conjunction alerts for user's watched satellites

### WatchedSatelliteController
- `index()`, `store()`, `destroy()` — manage user's tracked NORAD IDs

### BillingController
- `currentPlan()` — plan + status + entitlements + available_plans (sourced from EntitlementService)
- `subscribe()` — mock subscribe, records payment, syncs API key tiers
- `cancelSubscription()` — mock cancel, downgrades API keys to free
- `paymentHistory()` — last 20 payments for the user (GET /api/billing/history)

### Admin controllers (6)
- Dashboard stats, user CRUD + suspend/impersonate, subscription list, payment list + refund, API key overview
- `AdminPageController`: index, show, store, update, destroy, publish, unpublish — full CMS CRUD with audit logging

### PageController (public)
- `index()` — list published pages (title, slug, excerpt)
- `show(slug)` — single published page by slug; 404 if draft

---

## Docker Setup

### docker-compose.local.yml (dev)
- `backend` — Laravel dev server, volume mount for hot reload
- `frontend` — Vite HMR on :5173, `BACKEND_URL=http://backend:8000` for proxy
- `db` — MySQL 8 with healthcheck
- `mailpit` — catches all Laravel emails (UI at :8025)

### docker-compose.staging.yml
- `:staging` image tags, `APP_DEBUG=true`, isolated DB name

### docker-compose.yml (production)
- Traefik reverse proxy, Let's Encrypt TLS auto-provisioning
- `:latest` image tags, `APP_DEBUG=false`

### Backend Dockerfile (multi-stage)
- `vendor` stage: Composer install
- `production` stage: PHP-FPM + Nginx + Supervisor (Alpine)
- `development` stage: PHP CLI dev server (Composer installed via curl)

### Frontend Dockerfile (multi-stage)
- `builder` stage: `npm ci` + `vite build`
- `production` stage: Nginx serving `/dist`
- `Dockerfile.dev`: Vite HMR server

---

## CI/CD Pipeline

### ci.yml (triggers on PR to main/develop, push to develop)
```
backend-lint (Pint) → backend-test (Pest + MySQL service container)
  - Includes: composer audit (PHP dependency CVE scan, fails build on any vulnerability)
frontend-lint (ESLint) → frontend-test (Vitest) → frontend-build
  - frontend-test includes: npm audit --audit-level=high (JS dependency CVE scan)
```

### cd.yml (triggers on push to develop or main)
```
develop → build :staging images → deploy to staging via SSH
  - Pre-migration backup: mysqldump → ~/backups/pre-deploy-<timestamp>.sql (via docker compose exec db)
main    → build :latest images  → deploy to prod via SSH
  - Pre-migration backup: mysqldump → ~/backups/pre-deploy-<timestamp>.sql (via docker compose exec db)
```

Images pushed to GitHub Container Registry (ghcr.io).
Prod deploy also runs `php artisan config:cache` + `route:cache`.

### GitHub Secrets Required
```
STAGING_HOST, STAGING_USER, STAGING_SSH_KEY
PROD_HOST, PROD_USER, PROD_SSH_KEY
VITE_API_URL_STAGING
```

---

## Data Sources

| Source | Data | Auth | Refresh |
|---|---|---|---|
| CelesTrak | TLE data | None | Every 6h via `satellites:sync` |
| Space-Track | CDM conjunction messages (CDM_PUBLIC) | Free account (SPACE_TRACK_USER/PASS in .env) | Every 6h via `conjunctions:sync` |

**Key insight:** Don't proxy — cache. Fetch once on a schedule, serve from MySQL.

TLE groups:
- `GROUP=active` — all active satellites (~6k)
- `GROUP=cosmos-2251-debris`, `iridium-33-debris`, `fengyun-1c-debris`, `2019-006` — debris fields
- `GROUP=rocket-bodies` — spent stages

---

## Known Issues

- **satellite.js WASM build error**: top-level await incompatible with iife format — pre-existing issue, not introduced by recent work. Frontend build via CI may need `vite.config.js` adjustments.

- **GuestAccessTest network flakiness**: `it does not count the 10th request` fails when CelesTrak is unreachable or slow during test run (the guest-boundary test increments usage via a real `/api/conjunctions/25544` call). Pre-existing; not related to any feature work.

- **Sanctum `withToken()` cross-user isolation in tests**: When two users are created and both authenticated via `withToken()` within the same test, subsequent requests can occasionally resolve to the wrong user. Use `actingAs($user)` instead of `withToken($token)` for tests that verify per-user data isolation.

- **`php artisan serve` ignores Docker Compose `environment:` for HTTP requests**: PHP's built-in web server does NOT populate `$_ENV` or `getenv()` from the OS process environment in HTTP request handlers. phpdotenv loads `.env` as the sole source. Docker Compose `environment:` vars ARE visible in `docker compose exec` (Tinker/artisan) but NOT in HTTP requests. Always set DB/APP vars in `.env` directly — never rely on Docker env overriding `.env` for the HTTP server. Root cause of a hard login bug: Tinker showed MySQL/user-found, HTTP showed SQLite/user-missing.

---

## What's Built

- [x] CI/CD pipeline (ci.yml + cd.yml)
- [x] Docker multi-stage builds (backend + frontend)
- [x] docker-compose local/staging/prod
- [x] Makefile dev shortcuts
- [x] Laravel API: health, satellites, conjunctions, API keys
- [x] AuthenticateApiKey middleware with rate limiting
- [x] Full user auth (Sanctum bearer tokens)
- [x] Password reset flow
- [x] API key management (CRUD + rate limiting)
- [x] Watched satellites
- [x] Conjunction alerts
- [x] Mock billing (subscribe/cancel)
- [x] Admin panel (5 pages: dashboard, users, subscriptions, payments, API keys)
- [x] React Router v6 with protected + admin routes
- [x] AuthContext + ToastContext
- [x] Axios interceptors with error normalization
- [x] DebrisMonitor.jsx (full catalog globe, InstancedMesh) — public, no auth
- [x] SatelliteTracker.jsx — public, no auth; calls /api/conjunctions/{noradId} for risk analysis
- [x] ConjunctionAlerts.jsx — requires auth (gated in MainApp with AlertsAuthGate)
- [x] GA4 via react-ga4 + VITE_GA_MEASUREMENT_ID
- [x] Guest session system (UUID in localStorage → X-Guest-ID header → HandlePublicRequest → guest_usage table)
- [x] EntitlementService — centralized capability resolver for guest/user/API key
- [x] HandlePublicRequest middleware — unified actor resolution for public satellite/conjunction endpoints
- [x] GuestLimitReached banner in tracker (upgrade CTA when 10/day exhausted)
- [x] Guest remaining count in tracker panel footer (shows X analyses remaining for guests)
- [x] GuestUsage::record() — race-condition-safe (insertOrIgnore + atomic SQL increment); renamed from increment() to avoid Eloquent conflict
- [x] GuestAccessTest.php — full test coverage: guest quota, auth boundaries, API key path, admin guards
- [x] RefreshDatabase enabled globally for all feature tests
- [x] EntitlementService expanded: pricing, labels, catalog(), paidPlanKeys(), priceCents(), label() — single source for all plan data
- [x] Add-on foundation: users.addons JSON column; EntitlementService::forUser() merges per-user overrides on top of base plan
- [x] Subscriptions table: added name/stripe_id/stripe_price for Cashier compatibility
- [x] BillingController refactored: sources all plan data from EntitlementService, no duplicate constants
- [x] BillingController::paymentHistory() — GET /api/billing/history, last 20 payments
- [x] BillingController::currentPlan() — now includes entitlements + available_plans in response
- [x] BillingController::subscribe() — validation uses EntitlementService::paidPlanKeys() (no hardcoded strings)
- [x] SubscriptionFactory + PaymentFactory — for test fixtures
- [x] HasFactory added to Subscription + Payment models
- [x] AdminAccountSeeder — idempotent (firstOrCreate) admin in admin_accounts: admin@debris.monitor / admin
- [x] BillingTest.php — 13 tests: plan resolution, subscribe/cancel, payment history, auth guards
- [x] EntitlementTest.php — 14 tests: all actor types, add-on merging, catalog shape, capability checks
- [x] ApiUsage: explicit $table = 'api_usage' (Eloquent would default to api_usages — bug fixed)
- [x] GuestUsage: explicit $table = 'guest_usage' (same fix)
- [x] Globe MainApp: REGISTER + SIGN IN buttons top-left for guests; DASHBOARD + SIGN OUT for authenticated users
- [x] UserDashboard BillingTab: API-driven plan cards (no hardcoded PLANS), entitlement summary badges, payment history section
- [x] Admin auth separation: admin_accounts table, AdminAccount model, auth:admin Sanctum guard, AdminAuditLog
- [x] AdminAuthController: POST /api/admin/auth/login (3/min rate limit), logout, me
- [x] AdminAuthContext + adminClient.js — isolated from customer auth/axios
- [x] AdminLogin.jsx — dedicated admin login page at /admin/login
- [x] AdminRoute updated — checks AdminAuthContext (not user.role)
- [x] All admin pages switched to adminClient; role editing removed from AdminUsers
- [x] AdminAccountObserver — revokes all tokens immediately when is_active flips to false
- [x] AdminAccountFactory + inactive() state — for test fixtures
- [x] AdminAuthTest.php — 10 tests: login, wrong password, inactive account, token guard separation, deactivation revocation, logout
- [x] users.role column dropped (migration 2026_04_13_000000); safety step demotes any role='admin' users first
- [x] User::isAdmin() removed; role removed from #[Fillable] and from AuthController::userResource()
- [x] AdminUserSeeder deleted (replaced by AdminAccountSeeder)
- [x] sanctum guard explicitly configured with provider:users — AdminAccount tokens now 401 not 500 on customer routes
- [x] Login.jsx: removed role-based redirect (admins use /admin/login, customers always go to /)
- [x] UserDashboard.jsx: removed admin button that checked user.role
- [x] App.jsx: unified AdminAuthProvider — single instance wraps both /admin/login and /admin/*
- [x] AdminAuditLogController — GET /api/admin/audit-log, filters: action/admin_id/from/to, paginated 50/page, includes admin email+name
- [x] AdminAuditLogListTest.php — 9 tests: auth guards, pagination shape, null-actor entries, action/admin_id/date filters, newest-first ordering
- [x] AdminAuditLog.jsx — audit log page: action dropdown (all known events), date range filter, color-coded action badges, metadata summary, pagination; wired into nav + App.jsx routes
- [x] AdminAuditLog: event catalog as class constants — no ad-hoc strings anywhere in audit calls
- [x] AdminAuditLog: user_agent captured on every entry (migration 2026_04_13_000002)
- [x] AdminAuditLog: query scopes forAction(), forActor(), recent() — chainable
- [x] AdminAuditLog::record() accepts ?int $adminId — null actor valid for pre-auth failure events (migration 2026_04_13_000001)
- [x] Event names normalized: login.success, login.failed, login.failed_inactive, logout, impersonation.started, user.updated, user.suspended, user.activated, payment.refunded
- [x] user.updated logged for non-status field changes (e.g. name-only update)
- [x] Null-safe fix: $admin?->id — PHP 8 throws TypeError on null->id before ?? applies
- [x] AdminAuditLogTest.php — 16 tests: all event types, null-actor failure, user_agent capture, all 3 scopes + chaining, immutability
- [x] TOTP MFA for admin accounts (pragmarx/google2fa v9 + bacon/bacon-qr-code v3.1)
- [x] AdminMfaService — TOTP secret generation, QR code (SVG base64), verify, verifyWithSecret, recovery codes (8×XXXXX-XXXXX, bcrypt-hashed + encrypted:array), consumeRecoveryCode, challengeKey/pendingKey cache helpers
- [x] AdminAccount: encrypted cast for mfa_secret, encrypted:array for mfa_recovery_codes, hasMfa(), mfa_recovery_codes in fillable
- [x] Two-step login: credentials → if MFA → {mfa_required, mfa_token} (DB cache, 5min TTL) → POST /admin/auth/mfa/verify (throttle:admin-mfa 5/15min) → session token
- [x] AdminMfaController: GET setup (QR+secret), POST confirm (verify TOTP → persist → return recovery codes once), DELETE disable (password required)
- [x] MFA audit events: mfa.enabled, mfa.disabled, mfa.challenge_passed, mfa.challenge_failed, mfa.recovery_used
- [x] AdminMfaTest.php — 22 tests: direct login (no MFA), challenge flow, TOTP verify, recovery code consume + remove + audit, invalid/expired tokens, setup QR/cache, confirm persist/audit/reject, disable clear/audit/reject-wrong-password
- [x] AdminLogin.jsx — two-step form: step 1 credentials → step 2 TOTP code + recovery code toggle
- [x] AdminAuthContext.jsx — login() handles mfa_required; verifyMfa(mfaToken, code) as new exported function
- [x] AdminAccount.jsx — account page: profile info, MFA status badge, setup flow (QR → confirm → show recovery codes once), disable flow (password), sign out
- [x] AdminLayout.jsx — added Account nav item (◑); App.jsx — added /admin/account route
- [x] PageCmsTest.php — 39 tests: public visibility (published vs draft, content exposure, sort order), public show (by slug, 404 for draft, no auth needed), admin CRUD (create/update/delete with field validation, slug auto-gen + collision counter, status default), publish/unpublish + lifecycle, admin auth guards (unauthenticated + customer token both rejected), full audit log for all 5 page events, scopePublished
- [x] cookieConsent.test.jsx — 27 tests: defaults (banner shown, consent null, version mismatch → re-ask, malformed JSON), acceptAll/reject/saveCustom (state + localStorage + banner hide), settings modal open/close (restores banner when no saved consent), GA4 gate (not called before consent, not called on reject, called on analytics accept, not called without GA ID, correct on mount from stored consent), provider guard
- [x] guestAccess.test.jsx — rewritten for threads pool: static imports + proper mock set (CookieConsentProvider, CookieBanner, Footer); replaced vi.resetModules()+dynamic import pattern; fixed nested-router test; added auth-state-aware nav tests (guest sees SIGN IN, user sees DASHBOARD)
- [x] vite.config.js: pool: 'threads' — WSL2 cannot reliably fork processes across /mnt/c; threads avoids the 60s worker timeout; all 35 frontend tests pass
- [x] AdminPageController::store(): explicit $data['status'] ??= 'draft' — DB default not reflected on in-memory Eloquent instance without ->fresh(); ensures response always contains status
- [x] Validation error assertion pattern: use ->assertJsonPath('error.code', 'VALIDATION_ERROR') + expect($res->json('error.details'))->toHaveKey('field') — our custom envelope puts errors in error.details not errors (assertJsonValidationErrors is wrong for this project)
- [x] MFA QR double-btoa fix: AdminMfaService::generateQrBase64() returns already-base64-encoded SVG; AdminLogin.jsx was double-encoding with btoa() — removed btoa() so src uses qrCode directly (AdminAccount.jsx was already correct)
- [x] phpunit.xml: APP_KEY added (base64:QUFB...QUE=, 32-byte AES key) — fixes MissingAppKeyException for encrypted mfa_secret + mfa_recovery_codes casts in all test runners (Docker + CI)
- [x] backend/.env.testing created — required by CI workflow (cp .env.testing .env then php artisan key:generate); MySQL settings match the CI service container
- [x] MFA recovery codes: Download PDF button added to both recovery-code screens (AdminLogin forced-setup + AdminAccount in-session setup); jsPDF (frontend-only, no server call); PDF contains app name, label, date, codes, warnings; plaintext codes never leave the browser
- [x] Admin user creation: POST /admin/users endpoint; StoreUserRequest (name/email/password/status); USER_CREATED audit event with safe metadata; creates customer User only; CreateUserModal in AdminUsers.jsx with field-level validation error display
- [x] Tracker 3D visualization: satellite icon replaced from SphereGeometry dot → canvas-texture THREE.Sprite (solar panel silhouette, color-coded per satellite, glow halo); nearby objects now individual THREE.Mesh sphere markers per object (replacing THREE.Points cloud); SGP4-propagated objects update position every second; approx fallback (static ring) when TLE unavailable
- [x] Tracker panel: "Tracked Objects" → "NEARBY RISKY OBJECTS" section; conjunctionsLoading state + spinner; no-threats empty state ("NO THREATS DETECTED") when fetch completes with 0 results; conjunction stat labels improved to MISS DIST / COL. PROB / TCA; "SIMULATED RISK · REAL NORAD IDs" badge; per-item SGP4/~APPROX badge
- [x] ConjunctionController: secondary_norad_id field added — real NORAD IDs from Fengyun-1C/Cosmos 2251/Iridium 33 debris pools; risk scores remain simulated (Phase 1); source='simulated' field documents this
- [x] fetchSecondaryTle() — fetches CelesTrak TLE per secondary NORAD ID; approxNearbyPosition() — static ring fallback; createNearbyMarker() — per-object sphere marker
- [x] Track Satellite button fix: SatelliteTracker now auto-loads initialNoradId on mount via CelesTrak fetch + addSatellite(); previously just pre-filled the text input without tracking
- [x] Alerts loading guard: App.jsx shows "LOADING…" placeholder while AuthContext resolves (view='alerts' + loading=true), prevents blank area flicker between auth check and gate render
- [x] Notifications table migration (2026_04_18_000000) — required for database notification channel in ConjunctionAlertNotification
- [x] HasFactory added to ConjunctionAlert and WatchedSatellite models
- [x] WatchedSatelliteFactory — states: iss(), hubble(), forNorad(id, name), withFreshTle()
- [x] ConjunctionAlertFactory — states: high(), medium(), low(), past(), distant(), notified(), forPrimary(id, name)
- [x] AlertDemoSeeder — creates 3 demo users covering all Alerts UI states (demo@debris.monitor starter+alerts, free@debris.monitor upgrade gate, empty@debris.monitor no-sats empty state); idempotent (recreates expired alerts only); run with `make artisan cmd="db:seed --class=AlertDemoSeeder"`
- [x] DatabaseSeeder includes AlertDemoSeeder
- [x] AlertController — standardized response envelope ($this->success() for all cases including empty watched sats)
- [x] AlertTest.php — 19 tests: auth guards (401/403/200), empty states (no sats, sats with no alerts), retrieval (fields, risk_level derivation, TCA sort), scoping (unmonitored sats, past TCA, distant TCA, cross-user isolation, multi-sat), factory state tests
- [x] ConjunctionAlerts search picker: LOCAL_CATALOG (20 satellites, instant), deferred CelesTrak remote search (350ms debounce, merge), keyboard nav (↑↓/Enter/Esc), clear button, spinner, two-step unwatch confirm, tle_fresh display removed
- [x] Local satellite catalog: `satellites` + `tle_records` tables (migrations 2026_04_19_000000 + _000001)
- [x] Satellite + TleRecord models with HasFactory; SatelliteFactory (iss/debris/forNorad states), TleRecordFactory (fresh/stale states)
- [x] `php artisan satellites:sync` command — fetches 7 CelesTrak groups (stations, active, 3 debris fields, 2019-006, rocket-bodies), upserts satellites, rotates TLE records (is_current flag), idempotent, --dry-run support, scheduled every 6h
- [x] SatelliteSearchController rewritten — local DB only, no live CelesTrak, NORAD ID + name (LIKE) search, exact prefix scored first
- [x] SatelliteController::show/orbit — local DB first (TleRecord with is_current=true + <6h), CelesTrak fallback + auto-cache on miss. Response wrapped in standard {success, data} envelope (was bare JSON before)
- [x] WatchedSatelliteController::store — name resolution prefers local Satellite catalog; CelesTrak only if satellite not in catalog
- [x] SatelliteTracker.jsx — all 3 direct browser→CelesTrak fetches removed: initial auto-load, search, fetchSecondaryTle all route through /api/satellites/{id} or /api/satellites/search; new loadAndTrack() helper; quick-sat buttons call loadAndTrack() directly
- [x] SatelliteCatalogTest.php — 22 tests: search, show/fallback/cache, sync, catalog endpoint (empty/data/type-map/no-tle/cache-header), admin dashboard catalog stats
- [x] SatelliteTest.php updated — 3 tests adapted for new {success, data} envelope
- [x] satellites:sync scheduled every 6h in console.php (alongside conjunctions:check)
- [x] make setup runs satellites:sync automatically (with graceful failure warning); make sync-catalog standalone target added
- [x] GET /api/catalog — public endpoint, no auth, no rate limit; returns all satellites+current TLE from DB; Cache-Control: max-age=3600; empty array when not synced. CatalogController. Frontend uses type map (rocket_body→rocket)
- [x] DebrisMonitor.jsx — tries GET /api/catalog first; falls back to direct CelesTrak group fetches if catalog empty or unavailable; no behavior change when catalog empty; shows "LOCAL CATALOG" / "CELESTRAK DATA" source label in globe footer
- [x] AdminDashboardController — catalog stats added (total, synced_at, by_type) to /api/admin/dashboard response
- [x] AdminDashboard.jsx — CatalogStatus card shows object count, breakdown by type, last sync time, empty-state warning with make sync-catalog hint
- [x] CatalogController: ETag (md5 of max fetched_at) on every response; 304 on If-None-Match match; ?types= filter (satellite/debris/rocket, unknown tokens → empty); norad_id removed from payload (in line1[2:7])
- [x] SatelliteCatalogTest.php — 29 tests total (ETag, 304, types filter, unknown type, norad_id absent)
- [x] Real conjunction data pipeline: `conjunction_events` table (CDM ingest) + `SpaceTrackClient` service (session-cookie auth + CDM_PUBLIC fetch)
- [x] `conjunctions:sync` command — Space-Track CDM_PUBLIC ingest: login → fetch → upsert by cdm_id → generate conjunction_alerts for watched sats → notify users
- [x] `conjunction_alerts` augmented: `source` (sgp4/space_track_cdm) + `conjunction_event_id` nullable FK; `conjunctions:check` now tags alerts with source='sgp4'
- [x] `CheckConjunctionsCommand` updated: prefers local Satellite+TleRecord catalog for TLE; CelesTrak live fetch only as fallback for satellites not in local catalog
- [x] `ConjunctionController` updated: real CDM data first (scope: active ±24h to +7d, forObject); simulated fallback only when no CDM events; source field honest in both cases
- [x] `ConjunctionEvent` model: scopes active()/forObject(); riskScore() uses PC as primary signal (match thresholds), miss distance as floor; riskLevel() derived from riskScore()
- [x] `ConjunctionEventFactory` — states: high/medium/low/past/forPrimary/forSecondary; mimics Space-Track CDM_PUBLIC shape
- [x] `ConjunctionEventSeeder` — 6 demo CDM events for ISS/HST/GOES-16 pairs; generates conjunction_alerts for demo user; no credentials required
- [x] `DatabaseSeeder` includes `ConjunctionEventSeeder`
- [x] `config/services.php` — space_track.user/pass from SPACE_TRACK_USER/SPACE_TRACK_PASS env vars
- [x] `console.php` — `conjunctions:sync` scheduled every 6h (alongside `conjunctions:check` as SGP4 fallback)
- [x] Makefile: `sync-conjunctions`, `seed-conjunctions` targets added
- [x] `ConjunctionSyncTest.php` — 22 tests: no-creds exit, dry-run, CDM upsert/idempotency/multi-record/probability/emergency flag, graceful skip on malformed records, alert generation (sat1 watched/sat2 watched/unwatched/no-duplicate/source), controller CDM path (real data/simulated fallback/fields/risk levels/past events/secondary perspective), model helpers (riskScore high/low, riskLevel match)
- [x] SatelliteTracker.jsx — badge already renders "LIVE CDM DATA" when source='space_track_cdm' (was pre-wired from Phase 1); no changes needed

---

## What's Next (priority order)

1. **Space-Track CDM wiring** — set `SPACE_TRACK_USER` / `SPACE_TRACK_PASS` in `.env` and run `make sync-conjunctions` to see real CDM data in the Tracker. The full pipeline is in place; credentials are all that's needed for live data.

2. **UX polish** — loading states, source attribution improvements, tracker conjunction panel enhancements.

3. **Webhooks** — `webhooks` table, queue-based delivery with retry.

4. **Stripe billing** — `composer require laravel/cashier`, swap BillingController mock blocks for Cashier calls.

5. **Add-on products** — when first add-on ships, migrate `users.addons` JSON into a proper `user_addons` table.

---

## Laravel 13 Notes

- PHP attributes (`#[Fillable]`, `#[Hidden]`) instead of `$fillable`/`$hidden` arrays
- `php artisan install:api` scaffolds Sanctum
- Pest is the default test runner
- Exception rendering overridden in `bootstrap/app.php`

---

## Useful Commands

```bash
# Artisan
php artisan make:controller FooController
php artisan make:model Foo -mf          # model + migration + factory
php artisan make:command TleSyncCommand
php artisan migrate
php artisan migrate:fresh --seed
php artisan test --filter=ApiKeyTest

# Via Makefile (Docker)
make shell                              # bash into backend
make shell-db                           # mysql shell
make artisan cmd="migrate:fresh"

# Git
git checkout -b feat/tle-sync
git push -u origin feat/tle-sync
```

---

## Starting a New Claude Session

```
I'm building "Debris Monitor" — a satellite conjunction risk API + visualization platform.
Laravel 13 backend, React 19 + Vite frontend, Docker, GitHub Actions CI/CD.
Developing in WSL2. Repo at /mnt/c/projects/debris-monitor.
Read CLAUDE_CONTEXT.md in the repo root for full context.
Current task: [describe task]
```
