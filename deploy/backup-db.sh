#!/usr/bin/env bash
# Daily MySQL backup → local + Cloudflare R2.
# Install: copy to VPS, chmod +x, add to crontab (see below).
# Crontab: 0 2 * * * /opt/satview/deploy/backup-db.sh >> /var/log/satview-backup.log 2>&1
set -euo pipefail

# ── Config ────────────────────────────────────────────────────────────────────
COMPOSE_FILE="/opt/satview/docker-compose.yml"
DB_USER="debris"
DB_PASS="secret"          # change to match your production .env
DB_NAME="debris_prod"     # change to match MYSQL_DATABASE in .env
BACKUP_DIR="/var/backups/satview"
RCLONE_REMOTE="r2:satview-backups"   # r2 = rclone remote name, satview-backups = R2 bucket
KEEP_DAYS=30

# ── Dump ─────────────────────────────────────────────────────────────────────
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
FILENAME="db_${TIMESTAMP}.sql.gz"
FILEPATH="${BACKUP_DIR}/${FILENAME}"

mkdir -p "$BACKUP_DIR"

docker compose -f "$COMPOSE_FILE" exec -T db \
  mysqldump -u"$DB_USER" -p"$DB_PASS" \
  --single-transaction --routines --triggers "$DB_NAME" \
  | gzip > "$FILEPATH"

SIZE=$(du -sh "$FILEPATH" | cut -f1)
echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Backup created: $FILENAME ($SIZE)"

# ── Upload ────────────────────────────────────────────────────────────────────
rclone copy "$FILEPATH" "$RCLONE_REMOTE"
echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Uploaded to $RCLONE_REMOTE/$FILENAME"

# ── Prune ─────────────────────────────────────────────────────────────────────
find "$BACKUP_DIR" -name "db_*.sql.gz" -mtime +"$KEEP_DAYS" -delete
rclone delete "$RCLONE_REMOTE" --min-age "${KEEP_DAYS}d" --include "db_*.sql.gz"
echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Pruned backups older than ${KEEP_DAYS} days"
