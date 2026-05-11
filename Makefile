.PHONY: up down setup sync-catalog sync-catalog-celestrak sync-conjunctions sync-all seed-conjunctions test lint logs shell shell-backend shell-db backup restore prod-local prod-local-setup prod-local-down prod-local-reset prod-local-logs prod-local-shell

# ── Start everything ──────────────────────────────────────────
up:
	docker compose -f docker-compose.local.yml up --build

# Background mode
up-d:
	docker compose -f docker-compose.local.yml up --build -d

# ── Stop ─────────────────────────────────────────────────────
down:
	docker compose -f docker-compose.local.yml down

# Wipe DB volumes too
reset:
	docker compose -f docker-compose.local.yml down -v

# ── First-time setup ──────────────────────────────────────────
setup:
	docker compose -f docker-compose.local.yml up -d db
	@echo "Waiting for DB..."
	@sleep 8
	docker compose -f docker-compose.local.yml build backend
	@python3 -c "import re,base64,os; p='backend/.env'; c=open(p).read(); k='base64:'+base64.b64encode(os.urandom(32)).decode(); open(p,'w').write(re.sub(r'^APP_KEY=.*','APP_KEY='+k,c,flags=re.MULTILINE)); print('APP_KEY set.')"
	docker compose -f docker-compose.local.yml run --rm backend php artisan migrate --seed
	@echo "Syncing satellite catalog from Space-Track (falls back to CelesTrak if no credentials)..."
	docker compose -f docker-compose.local.yml run --rm backend php artisan satellites:sync || echo "Catalog sync failed. Run: make sync-catalog"
	@echo "Ready. Run: make up"

# Sync satellite catalog from Space-Track (default — requires SPACE_TRACK_USER/PASS in .env).
# Falls back to CelesTrak automatically if credentials are not set.
sync-catalog:
	docker compose -f docker-compose.local.yml run --rm backend php artisan satellites:sync

# Force CelesTrak as source (no credentials needed, but limited coverage + rate-limiting).
sync-catalog-celestrak:
	docker compose -f docker-compose.local.yml run --rm backend php artisan satellites:sync --source=celestrak

# Fetch real conjunction data from Space-Track CDM (requires SPACE_TRACK_USER/PASS in .env).
# Exits cleanly with a warning if credentials are not set.
sync-conjunctions:
	docker compose -f docker-compose.local.yml run --rm backend php artisan conjunctions:sync

# Sync everything from Space-Track in one shot: full satellite catalog + CDM conjunctions.
# Both commands fall back gracefully if SPACE_TRACK_USER/PASS are not set.
sync-all:
	docker compose -f docker-compose.local.yml run --rm backend php artisan satellites:sync
	docker compose -f docker-compose.local.yml run --rm backend php artisan conjunctions:sync

# Seed demo conjunction events (no Space-Track credentials required).
# Populates conjunction_events + conjunction_alerts for ISS/Hubble/GOES-16.
seed-conjunctions:
	docker compose -f docker-compose.local.yml run --rm backend php artisan db:seed --class=ConjunctionEventSeeder

# ── Tests ─────────────────────────────────────────────────────
# APP_KEY is generated at runtime by tests/bootstrap.php — no hardcoded key needed.
test:
	docker compose -f docker-compose.local.yml run --rm \
		backend sh -c "php artisan config:clear 2>/dev/null; php artisan test"

test-filter:
	docker compose -f docker-compose.local.yml run --rm \
		backend sh -c "php artisan config:clear 2>/dev/null; php artisan test --filter=$(filter)"

# ── Lint ──────────────────────────────────────────────────────
lint:
	docker compose -f docker-compose.local.yml run --rm backend ./vendor/bin/pint
	docker compose -f docker-compose.local.yml run --rm frontend npm run lint

# ── Logs ──────────────────────────────────────────────────────
logs:
	docker compose -f docker-compose.local.yml logs -f

logs-backend:
	docker compose -f docker-compose.local.yml logs -f backend

# ── Shells ────────────────────────────────────────────────────
shell shell-backend:
	docker compose -f docker-compose.local.yml exec backend sh

shell-db:
	docker compose -f docker-compose.local.yml exec db mysql -u debris -psecret debris_local

# ── DB backup / restore ───────────────────────────────────────
# Always runs inside the backend container where mysqldump points to the right server.
backup:
	docker compose -f docker-compose.local.yml exec backend php artisan db:backup

restore:
	docker compose -f docker-compose.local.yml exec backend php artisan db:restore $(file)

# ── Artisan shortcut ──────────────────────────────────────────
artisan:
	docker compose -f docker-compose.local.yml exec backend php artisan $(cmd)

# ── Prod-local (production build, local machine) ──────────────
# Mirrors the real production stack (PHP-FPM + nginx, scheduler, worker)
# but serves on http://localhost:8080 instead of satview.eu.
# Reads credentials from backend/.env — make sure APP_KEY is set.
#
# First time:
#   make prod-local-setup   ← builds backend, migrates DB, seeds admin user
#   make prod-local         ← builds all images and starts the full stack
#
# Subsequent runs:
#   make prod-local         ← rebuilds images and restarts (Docker cache = fast)
#
# Reset DB and start over:
#   make prod-local-reset && make prod-local-setup && make prod-local

prod-local-setup:
	docker compose -f docker-compose.prod-local.yml up -d db
	@echo "Waiting for DB to be healthy..."
	@until docker compose -f docker-compose.prod-local.yml exec db mysqladmin ping -h localhost --silent 2>/dev/null; do sleep 2; done
	docker compose -f docker-compose.prod-local.yml build backend
	docker compose -f docker-compose.prod-local.yml run --rm \
		-e APP_ENV=production -e APP_DEBUG=false \
		backend php artisan migrate --seed --force
	@echo "Syncing satellite catalog (Space-Track → CelesTrak fallback)..."
	docker compose -f docker-compose.prod-local.yml run --rm \
		-e APP_ENV=production -e APP_DEBUG=false \
		backend php artisan satellites:sync
	@echo "Syncing conjunction data..."
	docker compose -f docker-compose.prod-local.yml run --rm \
		-e APP_ENV=production -e APP_DEBUG=false \
		backend php artisan conjunctions:sync
	@echo "✅ DB ready. Run: make prod-local"

prod-local:
	docker compose -f docker-compose.prod-local.yml up --build -d
	@echo "Stack running at http://localhost:8090"

prod-local-down:
	docker compose -f docker-compose.prod-local.yml down

prod-local-reset:
	docker compose -f docker-compose.prod-local.yml down -v
	@echo "DB volume wiped. Run: make prod-local-setup"

prod-local-logs:
	docker compose -f docker-compose.prod-local.yml logs -f

prod-local-shell:
	docker compose -f docker-compose.prod-local.yml exec backend sh
