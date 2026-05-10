# 5. Data Pipeline

## 5.1 Overview

```mermaid
flowchart TD
    subgraph External["External Data Sources"]
        ST["Space-Track.org\nGP class + CDM_PUBLIC"]
        CT["CelesTrak\nTLE groups (fallback)"]
    end

    subgraph Pipeline["Backend Data Pipeline (runs every 6 hours)"]
        SS["satellites:sync\nArtisan command"]
        CS["conjunctions:sync\nArtisan command"]
        CC["conjunctions:check\nArtisan command (SGP4 fallback)"]
    end

    subgraph DB["MySQL"]
        SAT["satellites table"]
        TLE["tle_records table"]
        CE["conjunction_events table"]
        CA["conjunction_alerts table"]
        WS["watched_satellites table"]
    end

    subgraph Notify["Notification Queue"]
        Q["jobs table\ndatabase queue"]
        SMTP["SMTP Server\nalert email"]
    end

    ST -->|GP paginated by type| SS
    CT -->|TLE groups fallback| SS
    SS --> SAT
    SS --> TLE

    ST -->|CDM_PUBLIC /limit 1000| CS
    CS --> CE
    CS --> CA
    CA --> Q
    Q --> SMTP

    WS -->|watched norad IDs| CC
    TLE -->|local TLE data| CC
    CC --> CA
    CA --> Q
```

---

## 5.2 Satellite Catalog Sync (`satellites:sync`)

### Command signature
```
php artisan satellites:sync
    [--source=spacetrack]   # default; use --source=celestrak to force
    [--incremental]         # only fetch recently updated objects
    [--dry-run]             # parse without writing
    [--groups=]             # comma-separated CelesTrak groups (celestrak mode only)
```

### Space-Track flow (default)

```mermaid
sequenceDiagram
    participant CMD as satellites:sync
    participant ST as Space-Track.org
    participant DB as MySQL

    CMD->>ST: POST /ajaxauth/login (credentials)
    ST-->>CMD: session cookie

    loop For each OBJECT_TYPE: PAYLOAD, ROCKET BODY, DEBRIS
        CMD->>ST: GET /basicspacedata/query/class/gp/OBJECT_TYPE/{type}/DECAY_DATE/null-val
        note over CMD,ST: Paginated 2000 records/page<br/>cursor: NORAD_CAT_ID > lastSeen<br/>300ms delay between pages
        ST-->>CMD: JSON page[]
        CMD->>DB: Upsert satellite + upsertCurrentTle()
    end

    CMD->>CMD: runStalenessSweep(client)
    note over CMD: Satellites not seen in >7 days<br/>refreshed individually via fetchGpByNorad()
```

**Upsert logic:**
1. `Satellite::updateOrCreate(['norad_id' => ...], [...])` — creates or updates catalog metadata
2. `$satellite->upsertCurrentTle($line1, $line2, 'spacetrack')` — flips previous `is_current` to false, inserts new record
3. Updates `last_seen_at` timestamp

**Staleness sweep:** Satellites where `last_seen_at < now() - 7 days` are queried individually from Space-Track to verify they're still tracked (or to update stale TLEs). This catches objects that were missed in the paginated full sync.

### CelesTrak flow (fallback)

When Space-Track credentials are not configured, or `--source=celestrak` is passed:

1. Fetches SATCAT CSV from `https://celestrak.org/pub/satcat.csv` to build a type-classification map (NORAD ID → object type)
2. Fetches TLE groups: `active`, `fengyun-1c-debris`, `cosmos-2251-debris`, `iridium-33-debris`, `2019-006`
3. Each group provides TLE text (3-line format: name, line1, line2)
4. Object type determined by SATCAT lookup: `PAY`→satellite, `R/B`→rocket_body, `DEB`→debris

### Schedule
```
satellites:sync   every 6 hours   withoutOverlapping   runInBackground
```

---

## 5.3 Conjunction Sync (`conjunctions:sync`)

Fetches real CDM (Conjunction Data Message) records from Space-Track.

### Flow

```mermaid
sequenceDiagram
    participant CMD as conjunctions:sync
    participant ST as Space-Track.org
    participant DB as MySQL
    participant Q as Queue

    CMD->>ST: POST /ajaxauth/login
    ST-->>CMD: session cookie

    CMD->>ST: GET /basicspacedata/query/class/cdm_public/TCA/>now-1/orderby/TCA asc/LIMIT/1000
    ST-->>CMD: CDM records[]

    loop For each CDM record
        CMD->>DB: ConjunctionEvent::updateOrCreate(cdm_id)
        CMD->>DB: Check watched_satellites for sat1/sat2 NORAD IDs
        opt Watched satellite found
            CMD->>DB: ConjunctionAlert::create (dedup: same pair + TCA ±1h)
            CMD->>Q: dispatch ConjunctionAlertNotification
        end
    end
```

**CDM fields mapped:**

| CDM field | DB column |
|-----------|-----------|
| `CDM_ID` | `cdm_id` |
| `CREATION_DATE` | `created_at_cdm` |
| `TCA` | `tca` |
| `MIN_RNG` | `min_range_km` |
| `PC` | `probability` |
| `EMERGENCY_REPORTABLE` | `emergency_reportable` |
| `SAT1_OBJECT_DESIGNATOR` | `sat1_norad_id` |
| `SAT1_OBJECT_NAME` | `sat1_name` |
| `SAT2_OBJECT_DESIGNATOR` | `sat2_norad_id` |
| `SAT2_OBJECT_NAME` | `sat2_name` |

### Alert deduplication
Before creating a `ConjunctionAlert`, the command checks whether an alert already exists for the same `primary_norad_id + secondary_norad_id` pair with a TCA within ±1 hour of the current event. This prevents duplicate notifications when the same close approach is updated in successive CDM syncs.

### Schedule
```
conjunctions:sync   every 6 hours   withoutOverlapping   runInBackground
```

---

## 5.4 SGP4 Conjunction Screening (`conjunctions:check`)

Fallback screening using local TLE data when Space-Track credentials are unavailable, or to supplement CDM data.

### Flow

```mermaid
flowchart TD
    start([conjunctions:check]) --> fetch_watched[Load watched_satellites]
    fetch_watched --> for_each{For each\nwatched sat}
    for_each --> get_tle[Fetch TLE from local DB\nor CelesTrak fallback]
    get_tle --> propagate["Propagate position\nvia TlePropagator (SGP4)\nevery 10 min for 5 days"]
    propagate --> fetch_debris[Fetch threat catalog\nfrom CelesTrak debris groups]
    fetch_debris --> screen["Compute miss distances\nfor each debris object\nat each time step"]
    screen --> threshold{"miss_distance < 5 km?"}
    threshold -->|Yes| create_alert["Create ConjunctionAlert\nsource = sgp4"]
    threshold -->|No| for_each
    create_alert --> notify[Dispatch notification\nvia queue]
    notify --> for_each
```

**TlePropagator service:** Wraps `satellite.js`-compatible PHP SGP4 propagation logic. Computes ECI position vectors and converts to geodetic for distance calculation.

### Key parameters
- Screening horizon: **5 days** forward
- Time resolution: **10-minute intervals**
- Alert threshold: miss distance < **5 km**
- Threat catalog: `fengyun-1c-debris`, `cosmos-2251-debris`, `iridium-33-debris`, `2019-006`, `rocket-bodies`

---

## 5.5 Risk Scoring

Risk score is computed on `ConjunctionEvent` and `ConjunctionAlert` without stored computation — it's derived from stored fields:

```php
// From ConjunctionEvent::riskScore()
$fromDist = max(0, round(100 * max(0, 1 - $this->min_range_km / 10.0)));

if ($this->probability !== null && $this->probability > 0) {
    $fromPc = match(true) {
        $this->probability >= 0.001    => 90,
        $this->probability >= 0.0001   => 75,
        $this->probability >= 0.00001  => 55,
        $this->probability >= 0.000001 => 35,
        default                        => 15,
    };
    return max($fromDist, $fromPc);
}
return $fromDist;
```

| Risk Level | Score Range |
|-----------|-------------|
| HIGH | 70–100 |
| MEDIUM | 40–69 |
| LOW | 0–39 |

---

## 5.6 Notifications (`ConjunctionAlertNotification`)

Dispatched as a queued job from both `conjunctions:sync` and `conjunctions:check`.

```mermaid
sequenceDiagram
    participant SyncCmd as sync/check command
    participant Q as database queue
    participant Worker as queue:work worker
    participant SMTP as Mail Server
    participant DB as MySQL (notifications)

    SyncCmd->>Q: dispatch(ConjunctionAlertNotification)
    Worker->>Q: dequeue job
    Worker->>SMTP: Send email to user
    Worker->>DB: Store in notifications table (read_at = null)
    Worker->>Q: Delete job
```

The notification marks `conjunction_alerts.notified_at = now()` after dispatch to prevent re-notification on subsequent sync runs.

---

## 5.7 Database Backup (`db:backup`)

```
php artisan db:backup
```

Runs daily at 02:00 UTC via scheduler. Stores compressed `.sql.gz` files in `storage/app/backups/`. Retains the 7 most recent backups.

The VPS-level `deploy/backup-db.sh` script runs independently via cron and uploads to Cloudflare R2 (30-day retention). The two backup systems are complementary — local gives fast restore, R2 gives offsite protection.

---

## 5.8 Full Schedule Summary

| Command | Frequency | Overlap guard | Output log |
|---------|-----------|---------------|------------|
| `satellites:sync` | Every 6 h | Yes | `storage/logs/satellites.log` |
| `conjunctions:sync` | Every 6 h | Yes | `storage/logs/conjunctions.log` |
| `conjunctions:check` | Every 6 h | Yes | `storage/logs/conjunctions-sgp4.log` |
| `db:backup` | Daily 02:00 | Yes | `storage/logs/db-backup.log` |
