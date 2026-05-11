#!/usr/bin/env bash
# Restore a specific backup into the running MySQL container.
# Usage: ./restore-db.sh db_20260510_020001.sql.gz
#        ./restore-db.sh                              ← lists available backups
set -euo pipefail

COMPOSE_FILE="/opt/satview/docker-compose.yml"
DB_USER="debris"
DB_PASS="secret"
DB_NAME="debris_prod"
BACKUP_DIR="/var/backups/satview"
RCLONE_REMOTE="r2:satview-backups"

if [[ $# -eq 0 ]]; then
  echo "Available backups (local):"
  ls -lh "$BACKUP_DIR"/db_*.sql.gz 2>/dev/null || echo "  none found locally"
  echo ""
  echo "Available backups (R2):"
  rclone ls "$RCLONE_REMOTE" --include "db_*.sql.gz"
  echo ""
  echo "Usage: $0 <filename>"
  exit 0
fi

FILE="$1"
FILEPATH="${BACKUP_DIR}/${FILE}"

# Download from R2 if not already local
if [[ ! -f "$FILEPATH" ]]; then
  echo "Not found locally — downloading from R2..."
  rclone copy "${RCLONE_REMOTE}/${FILE}" "$BACKUP_DIR"
fi

echo "Restoring $FILE into $DB_NAME — this will OVERWRITE all current data."
read -rp "Type YES to continue: " confirm
[[ "$confirm" == "YES" ]] || { echo "Aborted."; exit 1; }

gunzip -c "$FILEPATH" | docker compose -f "$COMPOSE_FILE" exec -T db \
  mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME"

echo "Restore complete."
