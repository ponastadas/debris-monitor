#!/usr/bin/env bash
# Deploy SatView to the Hetzner server.
# Usage: ./infrastructure/deploy.sh
#
# Prerequisites:
#   1. Copy infrastructure/.env.production.example → infrastructure/.env.production and fill in values
#   2. Docker images must exist in GHCR (pushed by CI/CD or manually)
#
# To build and push images manually:
#   docker build --target production -t ghcr.io/ponastadas/debris-monitor-backend:latest ./backend
#   docker build -t ghcr.io/ponastadas/debris-monitor-frontend:latest ./frontend
#   docker push ghcr.io/ponastadas/debris-monitor-backend:latest
#   docker push ghcr.io/ponastadas/debris-monitor-frontend:latest

set -euo pipefail

SERVER="root@62.238.46.34"
SSH_KEY="$HOME/.ssh/satview_hetzner"
APP_DIR="/opt/satview"
ENV_FILE="infrastructure/.env.production"

# Run from repo root regardless of where the script is called from
cd "$(git rev-parse --show-toplevel)"

if [ ! -f "$ENV_FILE" ]; then
  echo "ERROR: $ENV_FILE not found."
  echo "Copy infrastructure/.env.production.example, fill in values, then re-run."
  exit 1
fi

GHCR_USER="ponastadas"
# Set GHCR_TOKEN in your environment before running:
#   export GHCR_TOKEN=ghp_...
if [ -z "${GHCR_TOKEN:-}" ]; then
  echo "ERROR: GHCR_TOKEN env var not set. Export your GitHub PAT before running."
  exit 1
fi

ssh_run() {
  ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no "$SERVER" "$@"
}

echo "── Step 1: Stop Nginx (conflicts with Traefik on ports 80/443)"
ssh_run "systemctl stop nginx && systemctl disable nginx" || true

echo "── Step 2: Login to GHCR on server"
ssh_run "echo '$GHCR_TOKEN' | docker login ghcr.io -u $GHCR_USER --password-stdin"

echo "── Step 3: Create Docker web network"
ssh_run "docker network create web 2>/dev/null" || true

echo "── Step 4: Copy files to server"
scp -i "$SSH_KEY" docker-compose.yml "$SERVER:$APP_DIR/docker-compose.yml"
scp -i "$SSH_KEY" "$ENV_FILE"        "$SERVER:$APP_DIR/.env"

echo "── Step 5: Pull latest images"
ssh_run "cd $APP_DIR && docker compose pull"

echo "── Step 6: Start services"
ssh_run "cd $APP_DIR && docker compose up -d"

echo "── Step 7: Wait for DB to be healthy"
ssh_run "cd $APP_DIR && until docker compose exec -T db mysqladmin ping -h localhost --silent; do sleep 2; done"

echo "── Step 8: Run migrations"
ssh_run "cd $APP_DIR && docker compose exec -T backend php artisan migrate --force"

echo "── Step 9: Clear caches"
ssh_run "cd $APP_DIR && docker compose exec -T backend php artisan config:cache && docker compose exec -T backend php artisan route:cache"

echo ""
echo "✓ Deploy complete — https://satview.eu"
echo "  Logs: ssh -i ~/.ssh/satview_hetzner $SERVER 'cd $APP_DIR && docker compose logs -f'"
