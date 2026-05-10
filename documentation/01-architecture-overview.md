# 1. Architecture Overview

## 1.1 System Context

```mermaid
graph TB
    subgraph Users["Users"]
        guest["👤 Guest User\nBrowses catalog\n10 free analyses/day"]
        user["👤 Registered User\nTracks satellites\nViews alerts (plan-gated)"]
        admin["👤 Admin\nManages users, billing\nCMS via /dashboard"]
        dev["👤 API Developer\nQueries conjunction data\nvia API key"]
    end

    subgraph SatView["SatView Platform"]
        platform["🌐 satview.eu\nReal-time orbital debris\nrisk monitoring"]
    end

    subgraph External["External Systems"]
        spacetrack["Space-Track.org\nAuthoritative GP catalog\n+ CDM conjunctions"]
        celestrak["CelesTrak\nFallback TLE catalog"]
        smtp["SMTP Server\nAlert & auth emails"]
        stripe["Stripe\nPayments (planned)"]
        ga4["Google Analytics\nTelemetry (consent-gated)"]
    end

    guest -->|HTTPS| platform
    user -->|HTTPS| platform
    admin -->|HTTPS| platform
    dev -->|HTTPS / X-API-Key| platform

    platform -->|GP catalog + CDM\nHTTPS / session cookie| spacetrack
    platform -->|Fallback TLE fetch\nHTTPS| celestrak
    platform -->|Alert + auth emails\nSMTP/587| smtp
    platform -->|Billing webhooks planned\nHTTPS| stripe
    platform -->|Pageview events\nHTTPS| ga4
```

---

## 1.2 Container Diagram

```mermaid
graph TB
    user["User / Admin / Developer"]

    subgraph platform["SatView Platform"]
        proxy["Reverse Proxy\nTraefik (prod) / nginx (local)\nTLS termination"]
        frontend["Frontend SPA\nReact 18 / Vite / Three.js\nGlobe, tracker, alerts, admin"]
        backend["Backend API\nLaravel 11 / PHP 8.3\nREST API + business logic"]
        db[("Database\nMySQL 8.0\nSatellites, conjunctions\nusers, billing")]
        worker["Queue Worker\nLaravel queue:work\nAsync email notifications"]
        scheduler["Scheduler\nLaravel schedule:run\nCron: sync + backup"]
    end

    user -->|HTTPS| proxy
    proxy -->|/* → static SPA\nHTTP| frontend
    proxy -->|/api/* → Laravel\nHTTP/FastCGI| backend
    frontend -->|Axios REST /api/*| backend
    backend -->|Eloquent ORM\nMySQL TCP 3306| db
    backend -->|Dispatch jobs\ndatabase queue| worker
    worker --> db
    scheduler -->|php artisan\nsatellites/conjunctions/db| backend
```

---

## 1.3 Technology Stack

### Backend
| Layer | Technology | Notes |
|-------|-----------|-------|
| Framework | Laravel 11 | PHP 8.3, API-only (no Blade routes except welcome) |
| Auth | Laravel Sanctum | Two guards: `sanctum` (users), `admin` (AdminAccount) |
| ORM | Eloquent | Soft deletes on ApiKey; all others hard-delete |
| Queue | Laravel Database Queue | Jobs table; `queue:work` in worker container |
| Scheduler | Laravel Scheduler | `schedule:run` every 60 s in scheduler container |
| HTTP Client | Guzzle + Laravel Http | Guzzle for Space-Track (cookie jar); Http facade elsewhere |
| Testing | Pest + PHPUnit | 18 feature test files, SQLite in-memory |
| Code style | Laravel Pint | PSR-12 enforced |

### Frontend
| Layer | Technology | Notes |
|-------|-----------|-------|
| Framework | React 18 | Vite 6, JSX (no TypeScript) |
| 3D Globe | Three.js | InstancedMesh for 32 K+ objects; satellite.js for SGP4 propagation |
| Routing | React Router v6 | SPA with nested routes for admin |
| HTTP | Axios | Two clients: `client.js` (user API) and `adminClient.js` (admin API) |
| State | React useState / useRef / Context | No Redux; localStorage for persistence |
| Auth | AuthContext / AdminAuthContext | Sanctum token stored in localStorage |
| Testing | Vitest + React Testing Library | 5 frontend test files |

### Infrastructure
| Component | Technology |
|-----------|-----------|
| Containerization | Docker / Docker Compose |
| Production proxy | Traefik 3 (TLS via Let's Encrypt ACME) |
| Image registry | GitHub Container Registry (GHCR) |
| CI/CD | GitHub Actions (`ci.yml` + `cd.yml`) |
| DB backups | `deploy/backup-db.sh` → Cloudflare R2 via rclone |

---

## 1.4 Request Flow — Typical API Call

```mermaid
sequenceDiagram
    participant Browser
    participant Proxy as Traefik / nginx
    participant Laravel as Laravel API
    participant DB as MySQL

    Browser->>Proxy: GET /api/conjunctions/25544
    Proxy->>Laravel: forward (Host, X-Forwarded-*)

    Laravel->>Laravel: HandlePublicRequest middleware
    note over Laravel: Resolves actor:<br/>1. Bearer token → user<br/>2. X-API-Key → api_key<br/>3. fallback → guest

    alt Guest (no auth)
        Laravel->>DB: GuestUsage.todayCount(guestId)
        DB-->>Laravel: count = 3
        Laravel->>DB: GuestUsage.record(guestId)
    end

    Laravel->>DB: ConjunctionEvent.forObject(25544).active()
    DB-->>Laravel: events[]
    Laravel-->>Proxy: JSON {success, data, meta}
    Proxy-->>Browser: 200 OK + X-Guest-Requests-Remaining: 6
```

---

## 1.5 Monorepo Layout

```
debris-monitor/
├── backend/                    Laravel 11 application
│   ├── app/
│   │   ├── Console/Commands/   Artisan commands (sync, check, backup)
│   │   ├── Http/
│   │   │   ├── Controllers/    Public + Admin REST controllers
│   │   │   ├── Middleware/     HandlePublicRequest, SecurityHeaders, EnsureIsAdmin
│   │   │   └── Requests/       Form request validation
│   │   ├── Models/             Eloquent models (15 total)
│   │   ├── Notifications/      ConjunctionAlertNotification
│   │   └── Services/           SpaceTrackClient, EntitlementService, TlePropagator
│   ├── database/
│   │   ├── migrations/         22 migration files
│   │   └── seeders/            Admin, demo alerts, pages
│   └── routes/
│       ├── api.php             All REST routes
│       └── console.php         Cron schedule definitions
│
├── frontend/                   React 18 SPA
│   └── src/
│       ├── App.jsx             Root component, routing, view switcher
│       ├── DebrisMonitor.jsx   Catalog view (Three.js globe)
│       ├── satellite-tracker.jsx Tracker view (Three.js + satellite.js)
│       ├── ConjunctionAlerts.jsx Alerts view
│       ├── api/                Axios client instances
│       ├── components/         NavBar, ProtectedRoute, CookieBanner…
│       ├── contexts/           Auth, AdminAuth, Toast, CookieConsent
│       ├── layouts/            AdminLayout, AuthLayout
│       └── pages/              Login, Register, UserDashboard, all Admin pages
│
├── deploy/                     Server-side operational scripts
│   ├── backup-db.sh            Daily MySQL → R2 backup
│   └── restore-db.sh           Restore from local or R2
│
├── docker/                     Shared nginx configs
├── docs/                       deployment.md
├── documentation/              This documentation suite
└── .github/workflows/          ci.yml, cd.yml
```
