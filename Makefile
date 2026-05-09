.PHONY: up down setup sync-catalog sync-conjunctions sync-all seed-conjunctions test lint logs shell shell-backend shell-db backup restore

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
	@echo "Syncing satellite catalog (fetches ~10k objects from CelesTrak — takes ~30s)..."
	docker compose -f docker-compose.local.yml run --rm backend php artisan satellites:sync || echo "⚠️  Catalog sync failed. Run: make sync-catalog"
	@echo "✅ Ready. Run: make up"

# Sync satellite catalog from CelesTrak (safe to run repeatedly).
# Run this after make setup if catalog is empty, or to refresh stale TLE data.
sync-catalog:
	docker compose -f docker-compose.local.yml run --rm backend php artisan satellites:sync

sync-catalog-spacetrack:
	docker compose -f docker-compose.local.yml run --rm backend php artisan satellites:sync --source=spacetrack

# Fetch real conjunction data from Space-Track CDM (requires SPACE_TRACK_USER/PASS in .env).
# Exits cleanly with a warning if credentials are not set.
sync-conjunctions:
	docker compose -f docker-compose.local.yml run --rm backend php artisan conjunctions:sync

# Sync everything at once: satellite catalog (CelesTrak) + conjunction data (Space-Track).
# conjunctions:sync exits cleanly if SPACE_TRACK_USER/PASS are not set in .env.
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
