# 1. Architecture Overview

## 1.1 System Context (C4 Level 1)

```mermaid
C4Context
    title SatView — System Context

    Person(guest,    "Guest User",       "Browses catalog, runs up to 10 free analyses/day")
    Person(user,     "Registered User",  "Tracks satellites, views alerts (plan-gated)")
    Person(admin,    "Admin",            "Manages users, subscriptions, CMS via /dashboard")
    Person(developer,"API Developer",    "Queries conjunction data via API key")

    System(satview, "SatView Platform", "Real-time orbital debris risk monitoring")

    System_Ext(spacetrack, "Space-Track.org",  "Authoritative satellite catalog + CDM conjunction data")
    System_Ext(celestrak,  "CelesTrak",        "Fallback TLE catalog when Space-Track unavailable")
    System_Ext(smtp,       "SMTP Server",      "Transactional email (alerts, password reset)")
    System_Ext(stripe,     "Stripe",           "Payment processing (integration pending)")
    System_Ext(ga4,        "Google Analytics", "Page-view telemetry (consent-gated)")

    Rel(guest,      satview,    "Views globe, searches satellites, runs analyses", "HTTPS")
    Rel(user,       satview,    "All guest features + alerts + watched satellites", "HTTPS")
    Rel(admin,      satview,    "Admin dashboard: users, billing, CMS, audit log",  "HTTPS")
    Rel(developer,  satview,    "Conjunction queries",  "HTTPS / X-API-Key")

    Rel(satview, spacetrack, "Fetches GP catalog + CDM conjunctions", "HTTPS / session cookie")
    Rel(satview, celestrak,  "Fallback TLE fetch",                    "HTTPS")
    Rel(satview, smtp,       "Sends alert + auth emails",              "SMTP/587")
    Rel(satview, stripe,     "Billing webhooks (planned)",             "HTTPS")
    Rel(satview, ga4,        "Pageview events",                        "HTTPS")
```

---

## 1.2 Container Diagram (C4 Level 2)

```mermaid
C4Container
    title SatView — Container Diagram

    Person(user, "User / Admin / Developer")

    System_Boundary(platform, "SatView Platform") {
        Container(proxy,    "Reverse Proxy",  "Traefik (prod) / nginx (local)", "TLS termination, routes /api/* → backend, /* → frontend")
        Container(frontend, "Frontend SPA",   "React 18 / Vite / Three.js",     "Globe visualization, tracker, alerts UI, admin panel")
        Container(backend,  "Backend API",    "Laravel 11 / PHP 8.3",           "REST API, business logic, queue worker, scheduler")
        Container(db,       "Database",       "MySQL 8.0",                       "Persistent storage: satellites, conjunctions, users, billing")
        Container(worker,   "Queue Worker",   "Laravel queue:work",              "Async jobs: email notifications")
        Container(scheduler,"Scheduler",      "Laravel schedule:run",            "Cron: satellite sync, conjunction sync, DB backup")
    }

    Rel(user,      proxy,     "HTTPS requests")
    Rel(proxy,     frontend,  "/*, /dashboard, /admin → static SPA", "HTTP")
    Rel(proxy,     backend,   "/api/* → Laravel",                     "HTTP/FastCGI")
    Rel(frontend,  backend,   "Axios REST calls to /api/*",           "HTTP")
    Rel(backend,   db,        "Eloquent ORM",                         "MySQL TCP 3306")
    Rel(backend,   worker,    "Dispatches jobs via database queue")
    Rel(scheduler, backend,   "php artisan {satellites,conjunctions,db}:*")
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
