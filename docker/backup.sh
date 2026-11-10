#!/usr/bin/env bash
# Nightly backup of the SocialMesh SQLite and storage (media) volumes.
#
#   ./docker/backup.sh [backup-dir]      # default: ./backups
#
# Archives both named volumes, keeps the newest KEEP copies of each, and never
# stops the app: the SQLite file is copied with `sqlite3 .backup` inside the app
# container so the archive is a consistent snapshot, not a torn file.
#
# Schedule (Linux/macOS):  0 3 * * * cd /path/to/socialmesh && ./docker/backup.sh
# Windows: run it from WSL or Git Bash via Task Scheduler.
set -euo pipefail

BACKUP_DIR="${1:-./backups}"
KEEP="${KEEP:-7}"
STAMP="$(date +%F-%H%M)"
COMPOSE=(docker compose -f docker-compose.production.yaml -f docker-compose.tunnel.yaml)

mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"
ABS_DIR="$(cd "${BACKUP_DIR}" && pwd)"

# 1. Consistent SQLite snapshot taken inside the running container with
#    VACUUM INTO (safe while the app and queue worker are writing), then copied out.
"${COMPOSE[@]}" exec -T app php -r '
    $db = getenv("DB_DATABASE") ?: "/var/www/html/database/sqlite/database.sqlite";
    @unlink("/tmp/backup.sqlite");
    (new PDO("sqlite:".$db))->exec("VACUUM INTO \"/tmp/backup.sqlite\"");
'
"${COMPOSE[@]}" cp app:/tmp/backup.sqlite "${ABS_DIR}/socialmesh-sqlite-${STAMP}.sqlite"
"${COMPOSE[@]}" exec -T app rm -f /tmp/backup.sqlite
gzip -f "${ABS_DIR}/socialmesh-sqlite-${STAMP}.sqlite"
# 2. Media/storage volume (read-only mount, app keeps running).
docker run --rm \
    -v socialmesh_storage:/source:ro \
    -v "${ABS_DIR}:/backup" \
    alpine tar -C /source -czf "/backup/socialmesh-storage-${STAMP}.tar.gz" .

# 3. Rotate: keep the newest ${KEEP} of each kind.
for prefix in socialmesh-sqlite socialmesh-storage; do
    # shellcheck disable=SC2012
    ls -1t "${ABS_DIR}/${prefix}-"* 2>/dev/null | tail -n +"$((KEEP + 1))" | xargs -r rm -f --
done

echo "Backup complete: ${ABS_DIR} (keeping ${KEEP} copies per volume)"
