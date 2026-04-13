# Debris Monitor — Project Context
> Last updated: 2026-04-13 (session 4 — separated admin auth, audit logging). Use this to onboard Claude Code in new sessions.

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
│   │       ├── AdminAccount.php       # separate admin_accounts table
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
│   │   │       └── AdminApiKeys.jsx
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
make artisan cmd="..."  # run artisan via Docker
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

### Admin auth (public — throttle: 3/min per IP)
```
POST   /api/admin/auth/login
```

### Admin protected (admin Sanctum token — auth:admin guard)
```
POST   /api/admin/auth/logout
GET    /api/admin/auth/me

GET    /api/admin/dashboard
GET    /api/admin/users
GET    /api/admin/users/{id}
PATCH  /api/admin/users/{id}
POST   /api/admin/users/{id}/impersonate
GET    /api/admin/subscriptions
GET    /api/admin/payments
POST   /api/admin/payments/{id}/refund
GET    /api/admin/api-keys
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
- Sanctum bearer tokens stored in `localStorage` (key: `dm_token`)
- Token flow: login → `personal_access_tokens` (tokenable_type=User) → Authorization header
- `AuthContext` restores session on mount; `useAuth()` exposes `{ user, loading, login, register, logout, refreshUser }`
- Axios client: `src/api/client.js` — attaches `dm_token` OR `X-Guest-ID` (mutually exclusive)
- Response 401: clears token, redirects to `/login`

### Admin Auth (SEPARATE — fully isolated from customer auth)
- Admins live in `admin_accounts` table — NOT in `users` table
- Sanctum `admin` guard (driver: sanctum, provider: admin_accounts) — only accepts tokens where `tokenable_type = AdminAccount`
- Admin tokens stored in `localStorage` (key: `dm_admin_token`)
- Token flow: `/api/admin/auth/login` → `personal_access_tokens` (tokenable_type=AdminAccount) → Authorization header
- `AdminAuthContext.jsx` manages admin session; `useAdminAuth()` exposes `{ admin, loading, login, logout }`
- Separate axios client: `src/api/adminClient.js` — attaches `dm_admin_token`, 401 redirects to `/admin/login`
- Route protection: `auth:admin` middleware (built-in Laravel auth) + `admin` alias (`EnsureIsAdmin` — blocks `is_active=false`)
- Rate limiting: 3 login attempts/min per IP (`throttle:admin-login`)
- Audit logging: every privileged action written to `admin_audit_logs` via `AdminAuditLog::record()`

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
```

---

## Frontend Environment Variables

```
VITE_GA_MEASUREMENT_ID=G-...          # Google Analytics 4 (blank until ready)
BACKEND_URL=http://backend:8000       # Docker-only, for Vite proxy (not exposed to browser)
```

- GA4 initialized in `main.jsx` via `react-ga4`
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
| `ConjunctionAlert` | scopes: `upcoming()`, `unnotified()`; methods: `riskLevel()`, `hoursUntilTca()` |
| `WatchedSatellite` | belongsTo User; stores NORAD ID + cached TLE |
| `Subscription` | belongsTo User; plan + billing dates |
| `Payment` | belongsTo User; payment records |

---

## Database Schema

| Table | Key columns |
|---|---|
| `users` | id, name, email, password, role (enum: user/admin — legacy, no longer used for admin access), addons (JSON, nullable), status, suspended_at |
| `admin_accounts` | id, name, email, password, is_active (bool), mfa_secret (nullable — TOTP extension point), last_login_at |
| `admin_audit_logs` | id, admin_account_id, action, target_type, target_id, metadata (JSON), ip, created_at (immutable — no updated_at) |
| `api_keys` | id, user_id, name, key (unique), tier, daily_limit, webhooks_enabled, satellite_limit, last_used_at, expires_at, soft_deletes |
| `api_usage` | id, api_key_id, endpoint, method, status_code, response_ms, ip, created_at (no updated_at) |
| `guest_usage` | id, identifier (guest UUID or IP), date, count; unique(identifier, date) |
| `watched_satellites` | id, user_id, norad_id (indexed), name, tle_line1, tle_line2, tle_fetched_at |
| `conjunction_alerts` | id, primary_norad_id, primary_name, secondary_norad_id, secondary_name, tca, miss_distance_km, probability, risk_score, notified_at |
| `subscriptions` | id, user_id, name (default 'default'), stripe_id (nullable), stripe_price (nullable), plan, status, current_period_start, current_period_end, canceled_at |
| `payments` | id, user_id, amount (cents), currency, status, description, stripe_charge_id (nullable), refunded_at |
| `personal_access_tokens` | (Sanctum) |

---

## Entitlement Model

**Single source of truth**: `app/Services/EntitlementService.php`

All actor types (guest / registered user / API key) resolve through `EntitlementService` to the same capability shape. No scattered `if plan === 'starter'` checks.

| Plan | requests/day | Alerts | API keys | Webhooks | Sat limit |
|---|---|---|---|---|---|
| guest | 10 | ✗ | ✗ | ✗ | — |
| free | 500 | ✗ | ✓ | ✗ | 5 |
| starter | 10,000 | ✓ | ✓ | ✓ | — |
| pro | 100,000 | ✓ | ✓ | ✓ | — |
| enterprise | unlimited | ✓ | ✓ | ✓ | — |

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
Data flow: TLE from CelesTrak → SGP4 via satellite.js → client-side propagation.

### ConjunctionAlerts.jsx
User's upcoming conjunction alerts for their watched satellites.

---

## Admin Panel

- Dark theme matching globe app (Orbitron + JetBrains Mono, `#0d1117`, `#00d4ff`)
- `AdminLayout.jsx` wraps all admin pages; uses `useAdminAuth()` for logout/identity display
- `AdminRoute` checks `AdminAuthContext` admin token (NOT user role)
- Login: `/admin/login` → `POST /api/admin/auth/login` → stores `dm_admin_token`
- Pages: dashboard stats, user management (suspend/activate only — role editing removed), subscription list, payment list + refund, API key overview
- All admin pages use `adminClient.js` (not `client.js`) to send `dm_admin_token`
- Audit log written for: login, logout, impersonate, user.active, user.suspended, payment.refund

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

---

## Backend Controllers

### AuthController
register, login (returns bearer token), logout, me (get/update), password change, forgot-password, reset-password

### SatelliteController
- `show($noradId)` — fetch TLE from CelesTrak, return name + TLE lines
- `orbit($noradId)` — return TLE lines for client-side propagation

### ConjunctionController
- `index($noradId)` — returns 9 simulated conjunction objects (Phase 2: real Space-Track CDM)

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

### Admin controllers (5)
- Dashboard stats, user CRUD + suspend/impersonate, subscription list, payment list + refund, API key overview

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
frontend-lint (ESLint) → frontend-test (Vitest) → frontend-build
```

### cd.yml (triggers on push to develop or main)
```
develop → build :staging images → deploy to staging via SSH
main    → build :latest images  → deploy to prod via SSH
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
| CelesTrak | TLE data | None | Every 6h (planned scheduler) |
| Space-Track | Full catalog + CDM conjunction messages | Free account | Daily (Phase 2) |

**Key insight:** Don't proxy — cache. Fetch once on a schedule, serve from MySQL.

TLE groups:
- `GROUP=active` — all active satellites (~6k)
- `GROUP=cosmos-2251-debris`, `iridium-33-debris`, `fengyun-1c-debris`, `2019-006` — debris fields
- `GROUP=rocket-bodies` — spent stages

---

## Known Issues

- **satellite.js WASM build error**: top-level await incompatible with iife format — pre-existing issue, not introduced by recent work. Frontend build via CI may need `vite.config.js` adjustments.

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

---

## What's Next (priority order)

1. **TLE sync command** — `php artisan tle:sync`
   - Fetch all TLE groups from CelesTrak, store in `satellites` + `tle_records` tables
   - Laravel Scheduler every 6 hours + cron in Docker

2. **Satellites + TleRecord migrations**
   - `satellites`: norad_id, name, type, country, launch_date
   - `tle_records`: satellite_id, line1, line2, epoch, fetched_at

3. **Wire frontend to backend API**
   - Replace direct CelesTrak fetches in DebrisMonitor.jsx with `/api/satellites`
   - Pass API key from `VITE_API_KEY` env var

4. **Real conjunction data** — Space-Track CDM integration

5. **Webhooks** — `webhooks` table, queue-based delivery with retry

5. **Stripe billing** — `composer require laravel/cashier`, swap BillingController mock blocks for Cashier calls

6. **Add-on products** — when first add-on ships, migrate `users.addons` JSON into a proper `user_addons` table (the JSON column is the bridge)

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
