.PHONY: up down setup test lint logs shell shell-backend shell-db

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
	docker compose -f docker-compose.local.yml run --rm backend php artisan key:generate
	docker compose -f docker-compose.local.yml run --rm backend php artisan migrate --seed
	@echo "✅ Ready. Run: make up"

# ── Tests ─────────────────────────────────────────────────────
test:
	docker compose -f docker-compose.local.yml run --rm backend php artisan test

test-filter:
	docker compose -f docker-compose.local.yml run --rm backend php artisan test --filter=$(filter)

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

# ── Artisan shortcut ──────────────────────────────────────────
artisan:
	docker compose -f docker-compose.local.yml exec backend php artisan $(cmd)
