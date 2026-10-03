# SocialMesh production deployment

This runbook deploys the prebuilt SocialMesh image on one VPS. The VPS does not build the application. The Compose stack runs the web app, one database-backed queue worker, and the scheduler. Existing host nginx handles public HTTP/HTTPS and proxies to SocialMesh on loopback.

## VPS and prerequisites

- Linux on `amd64` or `arm64`; both image architectures are published by GitHub Actions.
- Docker Engine with the Compose plugin.
- At least 1 GB RAM can run a light demo with one worker and SSR disabled, but it leaves little headroom. 2 GB is preferable. On a 1 GB VM, monitor memory and configure swap at the host level.
- Public inbound TCP ports 80 and 443 remain served by the existing nginx instance.
- The hostname must resolve to this VPS before Certbot can obtain a certificate.

## Prepare the VPS

Clone the private repository or copy these deployment files to the VPS. Compose pulls the published image and does not build source. The existing nginx site should proxy `socialmesh.ocus.qd.je` to `http://127.0.0.1:8082`.

GHCR inherits this repository's private visibility. Authenticate on the VPS with a GitHub account that can read the package. A classic personal access token with `read:packages` may be needed; create it in GitHub and enter it at the interactive password prompt. Do not put a token in the command line or shell history.

```sh
docker login ghcr.io -u revanthlol
cp .env.example.prod .env
chmod 600 .env
```

Generate a unique Laravel key locally and put the result in `.env` as `APP_KEY`:

```sh
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Edit `.env` and replace every `REPLACE_WITH_...` value. Set the R2 endpoint, bucket credentials, and a public HTTPS `AWS_URL` for the R2 media domain. Meta must be able to fetch media from that URL. Configure R2 CORS for the production app origin and expose the `ETag` header for direct uploads. Do not use development tunnel URLs.

The first user can register as the instance owner. Public registration remains disabled by default afterward.

## Start and check the stack

From the directory containing the Compose file and `.env`:

```sh
docker compose -f docker-compose.production.yaml pull
docker compose -f docker-compose.production.yaml up -d
docker compose -f docker-compose.production.yaml ps
docker compose -f docker-compose.production.yaml logs -f app caddy
curl --fail http://127.0.0.1:8082/up
```

Only `127.0.0.1:8082` is published by Docker; nginx retains ownership of public ports 80 and 443. Compose checks the app with the image's `healthcheck-octane` command. The image supervises the queue worker and scheduler in the same container. The defaults are one queue worker with a 256 MB recycle threshold and SSR disabled, to limit retained worker memory on a small VPS.

## DNS and HTTPS

The `A` record for `socialmesh.ocus.qd.je` should point to the VPS public IPv4:

```text
socialmesh.ocus.qd.je  ->  <VPS public IPv4>
```

No DNS change is made by this repository setup. The existing nginx site reverse proxies to `http://127.0.0.1:8082`. Once the reported Let's Encrypt rate limit clears, issue the certificate and enable the HTTP-to-HTTPS redirect with the existing nginx and Certbot setup:

```sh
sudo certbot --nginx -d socialmesh.ocus.qd.je --redirect
sudo nginx -t
curl --fail https://socialmesh.ocus.qd.je/up
```

The retry-after time provided for the current limit is `2026-10-03 14:56:12 UTC`; do not run Certbot for this name before then. Keep the existing SlotForge/GymERP nginx site files untouched.

The app trusts proxy headers because its published port is bound to loopback. `APP_URL`, `OCTANE_HTTPS`, and secure session cookies are set for HTTPS.

When the permanent domain is live, update Meta's callback allowlists manually:

- Facebook and Instagram: `https://socialmesh.ocus.qd.je/accounts/callback/meta`
- Threads: `https://socialmesh.ocus.qd.je/accounts/callback/threads`

## Persistent data and backups

Compose creates these named volumes:

| Volume | Contents |
| --- | --- |
| `socialmesh_sqlite` | SQLite database, queue, cache, and session data |
| `socialmesh_storage` | Laravel storage, including generated keys and runtime files |
Uploaded media is stored in R2 when configured; it is not part of the local volume backups. Back up the R2 bucket separately.

Create encrypted/off-host backups regularly. A simple local volume archive can be made as follows (do not store the only backup on the VPS):

```sh
mkdir -p backups
chmod 700 backups
docker compose -f docker-compose.production.yaml stop app
docker run --rm -v socialmesh_sqlite:/source:ro -v "$PWD/backups:/backup" alpine \
  tar -C /source -czf /backup/socialmesh-sqlite-$(date +%F).tar.gz .
docker run --rm -v socialmesh_storage:/source:ro -v "$PWD/backups:/backup" alpine \
  tar -C /source -czf /backup/socialmesh-storage-$(date +%F).tar.gz .
docker compose -f docker-compose.production.yaml start app
```

Restore with the app stopped. For example, to restore the SQLite archive:

```sh
docker compose -f docker-compose.production.yaml stop app
docker run --rm -v socialmesh_sqlite:/target -v "$PWD/backups:/backup:ro" alpine \
  tar -C /target -xzf /backup/<sqlite-archive>.tar.gz
docker run --rm -v socialmesh_storage:/target -v "$PWD/backups:/backup:ro" alpine \
  tar -C /target -xzf /backup/<storage-archive>.tar.gz
docker compose -f docker-compose.production.yaml start app
docker compose -f docker-compose.production.yaml ps
```

Replace the archive names with the files being restored. Preserve file ownership/permissions and verify app health after restart. Never run `docker compose down -v` for routine maintenance; it deletes the named volumes.

## Updates

Each push to `main` publishes `latest` and `sha-<full-commit>` tags for both architectures. Update to the latest successful build with:

```sh
docker compose -f docker-compose.production.yaml pull
docker compose -f docker-compose.production.yaml up -d
docker compose -f docker-compose.production.yaml ps
```

To pin a specific build, set `SOCIALMESH_IMAGE_TAG=sha-<full-commit>` in the shell before the Compose commands, or place it in the VPS `.env`. Keep a known-good tag available for rollback.

## Local development

For source editing and Vite HMR, use `docker-compose.dev.yml` as documented in the development setup. The production Compose file is only for the prebuilt GHCR image.
