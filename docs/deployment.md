# SatView — Deployment Guide

## Architecture Overview

```
GitHub repo (ponastadas/debris-monitor)
│
├── develop branch  ──CI passes──▶  build staging images  ──▶  deploy to staging.satview.eu
└── main branch     ──CI passes──▶  build prod images     ──▶  approve  ──▶  deploy to satview.eu
```

**Flow (both environments):**
1. Push merges to `develop` or `main`
2. `ci.yml` runs lint + tests + build — must pass
3. `cd.yml` triggers via `workflow_run` (only on CI success)
4. Docker images built with immutable SHA tag (`sha-<7chars>`) + convenience tag (`staging`/`latest`)
5. Images pushed to GHCR: `ghcr.io/ponastadas/debris-monitor-{backend,frontend}`
6. Compose file SCPed to server, then SSH deploy script runs:
   - GHCR login
   - Pull SHA-tagged images
   - DB backup → `~/backups/`
   - `docker compose up -d --remove-orphans`
   - `php artisan migrate --force`
   - Health check (`/api/health`)
7. Production: GitHub Environment approval gate fires before SSH (configure in GitHub Settings)

---

## Required GitHub Secrets

Set these under **Settings → Secrets and variables → Actions**:

| Secret | Description |
|---|---|
| `STAGING_HOST` | Staging server IP or hostname |
| `STAGING_USER` | SSH username on staging server |
| `STAGING_SSH_KEY` | Private SSH key for staging (Ed25519 recommended) |
| `PROD_HOST` | Production server IP or hostname |
| `PROD_USER` | SSH username on production server |
| `PROD_SSH_KEY` | Private SSH key for production |

> `GITHUB_TOKEN` is built-in and used automatically for GHCR push/pull.
> `VITE_API_URL_STAGING` is **not needed** — the frontend uses relative `/api` paths.
> All Laravel secrets (`APP_KEY`, `DB_PASSWORD`, `SPACE_TRACK_*`, etc.) live on the server `.env`, never in GitHub secrets.

---

## GitHub Environment Setup

In **Settings → Environments**, create two environments:

### `staging`
- No protection rules (auto-deploy on CI pass)
- Optional: restrict to `develop` branch

### `production`
- **Required reviewers**: add yourself (or your team)
- **Restrict pushes**: `main` branch only
- This creates an approval gate before every production deploy

---

## Server Setup Checklist

Run these steps once on each server (staging and production are separate VMs).

### 1. Install Docker

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
# Log out and back in
```

### 2. Create the Docker web network

Both staging and production compose files expect an external `web` network for Traefik:

```bash
docker network create web
```

### 3. Create deploy directories

**Staging server:**
```bash
mkdir -p ~/satview-staging ~/backups
```

**Production server:**
```bash
mkdir -p ~/satview-production ~/backups
```

The CD pipeline SCPs the compose file here automatically on each deploy. The `.env` file must be created manually (step 4).

### 4. Create server `.env`

This file is **never committed** and must be created manually on each server.

**Staging** (`~/satview-staging/.env`):
```env
APP_NAME=SatView
APP_ENV=staging
APP_KEY=base64:<generate with: php artisan key:generate --show>
APP_DEBUG=false
APP_URL=https://staging.satview.eu

LOG_CHANNEL=stderr

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=satview_staging
DB_USERNAME=satview
DB_PASSWORD=<strong-password>
DB_ROOT_PASSWORD=<strong-root-password>

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_LIFETIME=120

FRONTEND_URL=https://staging.satview.eu
SANCTUM_STATEFUL_DOMAINS=staging.satview.eu

ACME_EMAIL=admin@satview.eu

MAIL_MAILER=log

SPACE_TRACK_USER=
SPACE_TRACK_PASS=
```

**Production** (`~/satview-production/.env`):
```env
APP_NAME=SatView
APP_ENV=production
APP_KEY=base64:<generate — different from staging>
APP_DEBUG=false
APP_URL=https://satview.eu

LOG_CHANNEL=stderr

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=satview
DB_USERNAME=satview
DB_PASSWORD=<strong-password>
DB_ROOT_PASSWORD=<strong-root-password>

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_LIFETIME=120

FRONTEND_URL=https://satview.eu
SANCTUM_STATEFUL_DOMAINS=satview.eu

ACME_EMAIL=admin@satview.eu

MAIL_MAILER=smtp
MAIL_HOST=<smtp-host>
MAIL_PORT=587
MAIL_USERNAME=<smtp-user>
MAIL_PASSWORD=<smtp-password>
MAIL_FROM_ADDRESS=noreply@satview.eu
MAIL_FROM_NAME="SatView"

SPACE_TRACK_USER=<space-track-email>
SPACE_TRACK_PASS=<space-track-password>
```

### 5. Configure firewall

Only expose ports 80 and 443. SSH (22) should be restricted to your IP if possible.

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 22/tcp    # restrict to your IP: sudo ufw allow from <your-ip> to any port 22
sudo ufw enable
```

### 6. Set up SSH key for GitHub Actions

```bash
# Generate a deploy key on your local machine
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/satview_deploy

# Add the PUBLIC key to the server
ssh-copy-id -i ~/.ssh/satview_deploy.pub user@your-server

# Add the PRIVATE key as a GitHub secret (STAGING_SSH_KEY or PROD_SSH_KEY)
cat ~/.ssh/satview_deploy  # copy this entire output
```

### 7. First deploy (manual bootstrap)

The first deploy must be done manually since there's no existing DB:

**Staging:**
```bash
# On the staging server
cd ~/satview-staging

# Copy compose file from repo (or wait for first CD run which SCPs it)
# Then bring up DB first
docker compose -f docker-compose.staging.yml --project-name satview-staging up -d db

# Wait for MySQL to be ready
docker compose -f docker-compose.staging.yml --project-name satview-staging exec db mysqladmin ping -u root -p<root-password>

# Run migrations and seed admin account
docker compose -f docker-compose.staging.yml --project-name satview-staging run --rm backend php artisan migrate --force
docker compose -f docker-compose.staging.yml --project-name satview-staging run --rm backend php artisan db:seed --class=AdminAccountSeeder

# Bring up all services
docker compose -f docker-compose.staging.yml --project-name satview-staging up -d
```

**Production:** same pattern with `docker-compose.yml` and project name `satview-production`.

---

## Staging Deploy Flow

Automatic on every push to `develop` (after CI passes):

1. Dev merges PR into `develop`
2. CI runs (~2 min): lint → test → build
3. CD triggers automatically
4. Images built and pushed to GHCR with `sha-<7chars>` tag
5. Compose file SCPed to staging server
6. SSH deploy: GHCR login → DB backup → pull images → `up -d` → migrate → health check
7. URL: `https://staging.satview.eu`

---

## Production Deploy Flow

Triggered by push to `main`, **requires approval**:

1. Merge PR from `develop` into `main`
2. CI runs (~2 min): lint → test → build
3. CD triggers, reaches `deploy-production` job
4. **GitHub Environment approval gate fires** — reviewer approves in GitHub UI
5. Same deploy steps as staging
6. URL: `https://satview.eu`

---

## Rollback Procedure

Every deploy tags images with the commit SHA. To rollback:

### Option 1 — Redeploy previous SHA (recommended)

```bash
# On the server
SHORT_SHA=abc1234   # the SHA you want to rollback to

BACKEND_IMAGE=ghcr.io/ponastadas/debris-monitor-backend:sha-${SHORT_SHA}
FRONTEND_IMAGE=ghcr.io/ponastadas/debris-monitor-frontend:sha-${SHORT_SHA}

# Production:
BACKEND_IMAGE="${BACKEND_IMAGE}" FRONTEND_IMAGE="${FRONTEND_IMAGE}" \
  docker compose -f ~/satview-production/docker-compose.yml --project-name satview-production up -d
```

> Images are pruned only if older than 72 hours (`docker image prune --filter until=72h`), so recent SHA tags remain available on the server for at least 3 days.

### Option 2 — Restore DB backup

If the migration caused data issues:

```bash
# List available backups
ls -lt ~/backups/

# Restore (replace filename with the pre-deploy backup)
docker compose -f ~/satview-production/docker-compose.yml --project-name satview-production \
  exec -T db sh -c 'mysql -u root -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}"' \
  < ~/backups/prod-pre-deploy-20260101120000.sql
```

**Important**: restore DB backup BEFORE running the rollback compose up, or migrate the DB back down manually.

---

## Emergency Commands

```bash
# View live logs
docker compose -f ~/satview-production/docker-compose.yml --project-name satview-production logs -f backend

# Open bash in backend container
docker compose -f ~/satview-production/docker-compose.yml --project-name satview-production exec backend sh

# Run artisan command
docker compose -f ~/satview-production/docker-compose.yml --project-name satview-production \
  exec backend php artisan <command>

# Check scheduler is firing
docker compose -f ~/satview-production/docker-compose.yml --project-name satview-production logs scheduler

# Check queue worker
docker compose -f ~/satview-production/docker-compose.yml --project-name satview-production logs worker

# Force sync satellite catalog
docker compose -f ~/satview-production/docker-compose.yml --project-name satview-production \
  exec backend php artisan satellites:sync

# Force sync conjunctions
docker compose -f ~/satview-production/docker-compose.yml --project-name satview-production \
  exec backend php artisan conjunctions:sync

# Clear Laravel caches (if stale config after env change)
docker compose -f ~/satview-production/docker-compose.yml --project-name satview-production \
  exec backend sh -c "php artisan config:clear && php artisan route:clear && php artisan cache:clear"

# Manual DB backup
docker compose -f ~/satview-production/docker-compose.yml --project-name satview-production \
  exec -T db sh -c 'mysqldump -u root -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}"' \
  > ~/backups/manual-$(date +%Y%m%d%H%M%S).sql
```

---

## Automated DB Backups (Cloudflare R2)

Daily compressed mysqldump → Cloudflare R2, 30-day retention.

### 1. Create a Cloudflare R2 bucket

1. Log into [dash.cloudflare.com](https://dash.cloudflare.com) → **R2** → **Create bucket**
2. Name it `satview-backups`
3. Go to **R2 → Manage R2 API tokens** → **Create API token**
   - Permissions: **Object Read & Write** on `satview-backups` only
   - Copy the **Access Key ID** and **Secret Access Key**
4. Copy your **Account ID** from the R2 overview page (top right)

### 2. Install rclone on the VPS

```bash
curl https://rclone.org/install.sh | sudo bash
```

### 3. Configure rclone

```bash
rclone config
```

Choose `n` (new remote), then:

```
name: r2
type: s3
provider: Cloudflare
access_key_id: <your-r2-access-key>
secret_access_key: <your-r2-secret-key>
endpoint: https://<your-account-id>.r2.cloudflarestorage.com
acl: private
```

Test it:
```bash
rclone ls r2:satview-backups
```

### 4. Install the backup script

```bash
# Copy from repo (already present after git clone)
chmod +x /opt/satview/deploy/backup-db.sh
chmod +x /opt/satview/deploy/restore-db.sh
```

Edit the top of `backup-db.sh` to match your production credentials (DB_PASS, DB_NAME).

### 5. Schedule with cron

```bash
crontab -e
```

Add:
```
0 2 * * * /opt/satview/deploy/backup-db.sh >> /var/log/satview-backup.log 2>&1
```

Runs at 02:00 UTC every night. Check the log after the first run:
```bash
tail -f /var/log/satview-backup.log
```

### Restore a backup

```bash
# List all available backups (local + R2)
/opt/satview/deploy/restore-db.sh

# Restore a specific file
/opt/satview/deploy/restore-db.sh db_20260510_020001.sql.gz
```

---

## Scheduled Tasks (auto-running after deploy)

The `scheduler` container runs `php artisan schedule:run` every 60 seconds. Active schedules:

| Command | Frequency | Purpose |
|---|---|---|
| `satellites:sync` | Every 6 h | Fetch CelesTrak TLE data |
| `conjunctions:sync` | Every 6 h | Fetch Space-Track CDM data |
| `conjunctions:check` | Every 6 h | SGP4 fallback conjunction screening |
| `db:backup` | Daily 02:00 | DB backup to `storage/app/backups/` |

The `worker` container processes queued jobs: `ConjunctionAlertNotification` (email + database notifications) dispatches via queue.

---

## Notes and Risks

### `config:cache` and dynamic env
`php artisan config:cache` bakes all config values into a cache file. If you change `.env` on the server without rerunning `config:cache`, the app will use stale values. After any `.env` change:
```bash
docker compose ... exec backend php artisan config:clear
```
Or redeploy (CD always runs `config:cache` after `migrate`).

### GHCR token expiry
The `GITHUB_TOKEN` passed during CD is valid for the workflow run duration (~24h). For manual pulls on the server outside a workflow run, authenticate with a PAT:
```bash
echo "<PAT-with-read:packages>" | docker login ghcr.io -u <github-username> --password-stdin
```

### Same-server staging + production
If both environments run on the same server, use different ports for Traefik or run them on different Docker network namespaces. Each compose stack uses `--project-name` to isolate container names but Traefik port bindings (80/443) will conflict. Recommended: use separate VMs.

### MySQL 8 authentication
The compose files use `--default-authentication-plugin=mysql_native_password` for compatibility with the PHP PDO driver. MySQL 8 defaults to `caching_sha2_password` which requires an extra step to configure with older clients.
