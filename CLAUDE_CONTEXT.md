# Debris Monitor — Project Context
> Generated from planning session. Use this to onboard Claude Code in VS Code CLI.

---

## What This Project Is

**Debris Monitor** — a satellite conjunction risk monitoring platform. Dual purpose:
1. **CI/CD demo** for annual professional goal (show lint → test → Docker build → staging/prod deploy)
2. **Passive income SaaS** (API-first, monetized later via Stripe + Laravel Cashier)

Real product, real pipeline, real data. Not a toy.

---

## Competitive Landscape

| Product | Audience | Gap |
|---|---|---|
| spaceaware.io (Lyteworx) | Defense / Gov | Gated, no developer API |
| SpaceAware (Riskaware) | Enterprise | Complex, expensive |
| KeepTrack.space | Hobbyists | No risk scoring, CC BY-NC license, no webhooks |
| **Debris Monitor** | **Developers + Startups** | **Clean API, webhooks, freemium, commercial OK** |

---

## Tech Stack

| Layer | Tech |
|---|---|
| Frontend | React 18 + Vite + Three.js (InstancedMesh for performance) |
| Backend | Laravel 13 (PHP 8.3) |
| Database | MySQL 8 |
| Auth | Laravel Sanctum |
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
│       ├── ci.yml          # lint + test on every PR
│       └── cd.yml          # Docker build → staging/prod deploy
├── backend/                # Laravel 13 API
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── SatelliteController.php
│   │   │   │   ├── ConjunctionController.php
│   │   │   │   └── ApiKeyController.php
│   │   │   └── Middleware/
│   │   │       └── AuthenticateApiKey.php
│   │   └── Models/
│   │       ├── User.php
│   │       ├── ApiKey.php
│   │       └── ApiUsage.php
│   ├── database/
│   │   └── migrations/
│   │       └── ..._create_api_keys_and_usage_table.php
│   ├── routes/
│   │   └── api.php
│   ├── tests/
│   │   └── Feature/
│   │       ├── HealthTest.php
│   │       ├── SatelliteTest.php
│   │       └── ApiKeyTest.php
│   └── Dockerfile          # multi-stage: vendor → production → development
├── frontend/               # React + Vite
│   ├── src/
│   │   ├── App.jsx
│   │   ├── DebrisMonitor.jsx   # full catalog globe (main view)
│   │   ├── SatelliteTracker.jsx # single satellite drill-down
│   │   └── test/
│   │       ├── setup.js
│   │       └── App.test.jsx
│   ├── Dockerfile          # multi-stage: node builder → nginx
│   └── Dockerfile.dev      # dev only, Vite HMR
├── docker-compose.local.yml    # local dev (hot reload, mailpit)
├── docker-compose.staging.yml  # staging override
├── docker-compose.yml          # production (Traefik + Let's Encrypt)
└── Makefile                    # dev shortcuts
```

---

## Git Branch Strategy

```
main      → production (stable, protected)
develop   → staging (integration branch)
feat/*    → feature branches off develop
```

**Rule:** Steps 1–4 (scaffold) committed to `main`. Everything after on `develop`.

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
make up           # start all services with build
make up-d         # start in background
make down         # stop
make reset        # stop + wipe DB volumes
make setup        # first-time setup (generates key + migrates)
make test         # run Pest tests
make lint         # run Pint + ESLint
make logs         # tail all logs
make shell        # bash into backend container
```

---

## API Routes

```
GET  /api/health                    → public, health check
GET  /api/keys                      → auth:sanctum, list user's API keys
POST /api/keys                      → auth:sanctum, create API key
DEL  /api/keys/{id}                 → auth:sanctum, revoke API key

# All below require X-API-Key header
GET  /api/satellites/{noradId}      → TLE + live position
GET  /api/satellites/{noradId}/orbit → orbital path points
GET  /api/conjunctions/{noradId}    → nearby objects + risk scores
```

### API Key auth

Pass key in header:
```
X-API-Key: dm_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Or as query param for quick testing:
```
/api/satellites/25544?api_key=dm_live_xxx
```

### Rate limit headers on every response

```
X-RateLimit-Limit:     100
X-RateLimit-Remaining: 87
X-RateLimit-Reset:     1712188799
X-API-Tier:            free
```

---

## Monetization Tiers (future — architecture already supports it)

| Tier | Price | Daily limit | Webhooks | Satellites |
|---|---|---|---|---|
| Free | $0 | 100 req | No | 5 |
| Starter | $19/mo | 10,000 req | Yes | Unlimited |
| Pro | $79/mo | 100,000 req | Yes | Unlimited |
| Enterprise | Custom | Unlimited | Yes | Unlimited |

**Stripe integration:** Laravel Cashier (`composer require laravel/cashier`).
`ApiKey::tierDefaults()` is the single source of truth for limits — update it when adding billing.

---

## Data Sources

| Source | Data | Auth | Refresh |
|---|---|---|---|
| CelesTrak (celestrak.org) | TLE data, active sats | None | Every 6h via scheduler |
| Space-Track (space-track.org) | Full catalog + CDM conjunction messages | Free account | Daily |

**Key insight:** Don't proxy — cache. Fetch once on a schedule, serve all users from MySQL. Rate limits become irrelevant.

TLE groups fetched:
- `GROUP=active` — all active satellites (~6,000)
- `GROUP=cosmos-2251-debris` — Cosmos 2251 collision debris
- `GROUP=iridium-33-debris` — Iridium 33 collision debris
- `GROUP=fengyun-1c-debris` — FY-1C ASAT test debris
- `GROUP=2019-006` — Indian ASAT test debris
- `GROUP=rocket-bodies` — spent rocket stages

---

## Frontend — Globe Architecture

### DebrisMonitor.jsx (main view)
Full catalog visualization — all ~10,000+ tracked objects.

**Key tech decision:** `THREE.InstancedMesh` — renders all objects in a single WebGL draw call. spaceaware.io renders each separately → 0 FPS. This renders at 60 FPS.

Object categories with colors:
- 🔵 Active satellites `#388bfd`
- 🔴 Debris `#f85149`
- 🟡 Rocket bodies `#d29922`
- ⚫ Unknown `#8b949e`

Features:
- Full catalog from 6 CelesTrak TLE sources
- Category toggles (show/hide each type)
- Orbital distribution (LEO/MEO/HEO/GEO)
- Time simulation with speed controls (1×, 10×, 1min/s, 10min/s)
- Live FPS counter
- Search bar (NORAD ID or name)
- Selected object detail panel

### SatelliteTracker.jsx (drill-down view)
Single satellite focus — enter NORAD ID, see:
- Live position on globe
- Orbital path (90 min)
- Nearby debris objects (color-coded risk)
- Miss distance, collision probability, TCA per object
- Risk score banner (LOW / MODERATE / HIGH)

Data flow: fetches TLE from CelesTrak → runs SGP4 via satellite.js → propagates position client-side.

---

## Backend Controllers

### SatelliteController
- `show($noradId)` — fetches TLE from CelesTrak, returns name + TLE lines + timestamp
- `orbit($noradId)` — returns TLE lines for client-side propagation

### ConjunctionController
- `index($noradId)` — returns 9 simulated conjunction objects with risk scores
- Phase 2: replace simulation with Space-Track CDM API

### ApiKeyController
- `index()` — list user's keys with today's usage count
- `store()` — generate new key (shown once), assign free tier defaults
- `destroy($id)` — soft delete (revoke)

---

## AuthenticateApiKey Middleware

Flow:
1. Extract key from `X-API-Key` header (or `?api_key=` query param)
2. Look up in `api_keys` table (active scope)
3. Check daily rate limit (`api_usage` count for today)
4. If limited → 429 with `upgrade_url`
5. Attach key to request for controllers
6. Process request
7. Log to `api_usage` table
8. Append rate limit headers to response

---

## Database Schema

### `api_keys`
```
id, user_id, name, key (unique, 64 chars), tier,
daily_limit, webhooks_enabled, satellite_limit,
last_used_at, expires_at, timestamps, soft_deletes
```

### `api_usage`
```
id, api_key_id, endpoint, method, status_code,
response_ms, ip, created_at (no updated_at)
```

---

## CI/CD Pipeline

### ci.yml — triggers on PR to main/develop

```
backend-lint (Pint)
    └── backend-test (Pest + MySQL service container)

frontend-lint (ESLint)
    └── frontend-test (Vitest)
            └── frontend-build (vite build)
```

### cd.yml — triggers on push to develop or main

```
Push to develop:
  config → build Docker images (:staging tag) → deploy to staging via SSH

Push to main:
  config → build Docker images (:latest tag) → deploy to prod via SSH
```

Images pushed to GitHub Container Registry (ghcr.io).

---

## Docker Setup

### Local (docker-compose.local.yml)
- `backend` — Laravel dev server with volume mount (hot reload)
- `frontend` — Vite dev server with HMR
- `db` — MySQL 8 with healthcheck
- `mailpit` — catches all Laravel emails (UI at :8025)

### Staging (docker-compose.staging.yml)
- Override: `:staging` image tags, `APP_DEBUG=true`, staging domain, isolated DB name

### Production (docker-compose.yml)
- Traefik reverse proxy with automatic Let's Encrypt TLS
- `:latest` image tags, `APP_DEBUG=false`
- Named volumes for DB data and certs

### Backend Dockerfile (multi-stage)
- `vendor` stage: Composer install
- `production` stage: PHP-FPM + Nginx + Supervisor (Alpine)
- `development` stage: PHP CLI dev server

### Frontend Dockerfile (multi-stage)
- `builder` stage: `npm ci` + `vite build`
- `production` stage: Nginx serving `/dist`
- `Dockerfile.dev`: Vite HMR server

---

## GitHub Secrets Required (for CD)

```
STAGING_HOST         Staging server IP/hostname
STAGING_USER         SSH username
STAGING_SSH_KEY      Private SSH key (PEM)
PROD_HOST            Production server IP/hostname
PROD_USER            SSH username
PROD_SSH_KEY         Private SSH key (PEM)
VITE_API_URL_STAGING Backend URL for staging frontend builds
```

---

## What's Been Built (session output)

- [x] `.github/workflows/ci.yml`
- [x] `.github/workflows/cd.yml`
- [x] `backend/Dockerfile`
- [x] `frontend/Dockerfile`
- [x] `frontend/Dockerfile.dev`
- [x] `docker-compose.yml`
- [x] `docker-compose.local.yml`
- [x] `docker-compose.staging.yml`
- [x] `Makefile`
- [x] `backend/routes/api.php`
- [x] `backend/app/Http/Controllers/SatelliteController.php`
- [x] `backend/app/Http/Controllers/ConjunctionController.php`
- [x] `backend/app/Http/Controllers/ApiKeyController.php`
- [x] `backend/app/Http/Middleware/AuthenticateApiKey.php`
- [x] `backend/app/Models/ApiKey.php`
- [x] `backend/app/Models/ApiUsage.php`
- [x] `backend/app/Models/User.php` (updated with apiKeys relationship)
- [x] `backend/database/migrations/..._create_api_keys_and_usage_table.php`
- [x] `backend/tests/Feature/HealthTest.php`
- [x] `backend/tests/Feature/SatelliteTest.php`
- [x] `backend/tests/Feature/ApiKeyTest.php`
- [x] `frontend/src/DebrisMonitor.jsx` (full catalog globe)
- [x] `frontend/src/SatelliteTracker.jsx` (single sat drill-down)
- [x] `README.md`
- [x] `debris-monitor-setup-guide.md` (full step-by-step from scratch)

---

## What's Next (in priority order)

1. **ApiKey factory** — needed for tests to pass
   ```bash
   php artisan make:factory ApiKeyFactory
   ```
   Definition:
   ```php
   public function definition(): array {
     return [
       'user_id'          => User::factory(),
       'name'             => 'Test Key',
       'key'              => ApiKey::generate(),
       'tier'             => 'free',
       'daily_limit'      => 100,
       'webhooks_enabled' => false,
       'satellite_limit'  => 5,
     ];
   }
   ```

2. **TLE sync command** — `php artisan tle:sync`
   - Fetch all TLE groups from CelesTrak
   - Store in `satellites` + `tle_records` tables
   - Run via Laravel Scheduler every 6 hours
   - Cron entry in Docker: `* * * * * php artisan schedule:run`

3. **Satellites + TleRecord migrations**
   - `satellites` table: norad_id, name, type, country, launch_date
   - `tle_records` table: satellite_id, line1, line2, epoch, fetched_at

4. **Wire frontend to backend API**
   - Replace CelesTrak direct fetch in DebrisMonitor.jsx with `/api/satellites`
   - Pass API key from env var (`VITE_API_KEY` in `.env.local`)

5. **Conjunction risk engine**
   - Replace simulated data in ConjunctionController with real Space-Track CDM data
   - Space-Track account needed: https://www.space-track.org/auth/createAccount

6. **User auth + API key dashboard**
   - Registration / login (Sanctum)
   - Simple dashboard to create/revoke API keys
   - Show today's usage vs limit

7. **Webhooks**
   - `webhooks` table: user_id, url, events[], secret
   - Dispatch when risk score exceeds threshold
   - Queue-based delivery with retry

8. **Stripe billing** (when ready)
   ```bash
   composer require laravel/cashier
   ```
   - Sync tier upgrades to `ApiKey::tierDefaults()`
   - Subscription webhooks from Stripe → update key tier

---

## Laravel 13 Notes

This project uses Laravel 13 (newer than typical training data). Key differences noticed:
- Uses PHP attributes (`#[Fillable]`, `#[Hidden]`) instead of `$fillable`/`$hidden` arrays
- `php artisan install:api` scaffolds Sanctum
- Pest is the default test runner

---

## Useful Commands Reference

```bash
# Laravel
php artisan make:controller FooController
php artisan make:model Foo -mf          # model + migration + factory
php artisan make:command TleSyncCommand
php artisan make:middleware FooMiddleware
php artisan migrate
php artisan migrate:fresh --seed
php artisan test
php artisan test --filter=ApiKeyTest

# Docker shortcuts (via Makefile)
make shell                              # bash into backend
make shell-db                           # mysql shell
make artisan cmd="migrate:fresh"        # run artisan via Docker

# Git
git checkout -b feat/tle-sync           # new feature branch
git push -u origin feat/tle-sync        # push + track
```

---

## Context for Claude Code

When starting a new session in VS Code CLI, paste this prompt:

```
I'm building "Debris Monitor" — a satellite conjunction risk API and visualization platform.
Laravel 13 backend, React + Vite frontend, Docker, GitHub Actions CI/CD.
We're developing in WSL2. The repo is at ~/projects/debris-monitor.
Read the file CLAUDE_CONTEXT.md in the repo root for full project context.
Current task: [describe what you want to do]
```
