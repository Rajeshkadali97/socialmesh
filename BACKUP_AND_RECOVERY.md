# SocialMesh — Backup & Disaster Recovery Guide

## 1. What Must Be Backed Up

SocialMesh state lives in three places:
1. **Environment & Encryption Key (`/opt/apps/socialmesh/.env`)**:
   - Contains `APP_KEY` (required to decrypt all stored `connected_accounts` and `google_drive.oauth` tokens) and OAuth credentials.
   - Store a secure offline copy of `.env` in Ebin Tech's password manager before going live.
2. **SQLite Database Volume (`socialmesh_sqlite`)**:
   - Contains workspaces, users, connected accounts, encrypted OAuth tokens, scheduled/published posts, campaigns, and media metadata (`/var/www/html/database/sqlite/database.sqlite`).
3. **Media Storage Volume (`socialmesh_storage`)**:
   - Contains local/public media files and Passport keys (`/var/www/html/storage`).
   - Note: Original assets archived to **Google Drive** and published objects in **Cloudflare R2** live externally and are never deleted automatically by SocialMesh.

---

## 2. Automated Nightly Backups (`docker/backup.sh`)

The repository includes `./docker/backup.sh` and `php artisan backup:ebin-cloud`, which perform an online, zero-downtime dual-destination backup:
1. Runs `VACUUM INTO "/tmp/backup.sqlite"` inside `socialmesh-app` so SQLite WAL state is snapshotted atomically without stopping the web server or queue worker.
2. Compresses the database snapshot locally to `backups/socialmesh-sqlite-YYYY-MM-DD-HHMM.sqlite.gz` (independent local recovery path).
3. Archives the `socialmesh_storage` volume read-only into `backups/socialmesh-storage-YYYY-MM-DD-HHMM.tar.gz`.
4. Uploads a SHA-256 verified SQLite backup copy directly to **Ebin Cloud** (`Applications/SocialMesh/Backups/socialmesh-sqlite-YYYY-MM-DD-HHMM.sqlite.gz` routed to `gdrive_archive`) and runs an isolated `PRAGMA integrity_check` restore test (`php artisan backup:ebin-cloud --verify-restore`).
5. Rotates local backups automatically (`KEEP=30` via `socialmesh-backup.service` systemd timer at `21:30 UTC`).

### Running a Manual Backup

```bash
cd /opt/apps/socialmesh
./docker/backup.sh /opt/apps/socialmesh/backups
```

### Scheduling Nightly Cron on the VPS

Add a user crontab entry (isolated to `/opt/apps/socialmesh`):

```cron
0 3 * * * cd /opt/apps/socialmesh && ./docker/backup.sh /opt/apps/socialmesh/backups >> /opt/apps/socialmesh/backups/backup.log 2>&1
```

---

## 3. Restore & Rollback Procedures

### A. Restoring the SQLite Database from Backup

```bash
cd /opt/apps/socialmesh

# 1. Stop only the SocialMesh app container (never touch RK Inventory containers)
docker compose -p socialmesh -f docker-compose.production.yaml -f docker-compose.tunnel.yaml stop app

# 2. Decompress the chosen backup and copy into the socialmesh_sqlite volume
gunzip -k /opt/apps/socialmesh/backups/socialmesh-sqlite-YYYY-MM-DD-HHMM.sqlite.gz
docker run --rm \
  -v socialmesh_sqlite:/target \
  -v /opt/apps/socialmesh/backups:/backup:ro \
  alpine cp /backup/socialmesh-sqlite-YYYY-MM-DD-HHMM.sqlite /target/database.sqlite

# 3. Start SocialMesh app container and run any pending migrations
docker compose -p socialmesh -f docker-compose.production.yaml -f docker-compose.tunnel.yaml start app
docker compose -p socialmesh -f docker-compose.production.yaml -f docker-compose.tunnel.yaml exec app php artisan migrate --force
```

### B. Restoring the Storage Volume (`socialmesh_storage`)

```bash
cd /opt/apps/socialmesh
docker compose -p socialmesh -f docker-compose.production.yaml -f docker-compose.tunnel.yaml stop app

docker run --rm \
  -v socialmesh_storage:/target \
  -v /opt/apps/socialmesh/backups:/backup:ro \
  alpine sh -c "cd /target && tar -xzf /backup/socialmesh-storage-YYYY-MM-DD-HHMM.tar.gz"

docker compose -p socialmesh -f docker-compose.production.yaml -f docker-compose.tunnel.yaml start app
```

### C. Application Code Rollback

```bash
cd /opt/apps/socialmesh
git checkout <previous-known-good-commit>
docker compose -p socialmesh -f docker-compose.production.yaml -f docker-compose.tunnel.yaml up -d --build
```
