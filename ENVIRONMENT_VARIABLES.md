# SocialMesh — Environment Variables Reference

Never commit real secrets, API keys, or OAuth tokens to Git. Configure these in `.env` (with `chmod 600 .env` in production).

## 1. Core Application & Runtime

| Variable | Required | Default / Example | Purpose |
|---|---|---|---|
| `APP_NAME` | Yes | `SocialMesh` | Application display name |
| `APP_ENV` | Yes | `production` | Environment (`local`, `testing`, `production`) |
| `APP_KEY` | Yes | `base64:...` | 32-byte encryption key for sessions & stored OAuth tokens |
| `APP_DEBUG` | Yes | `false` | Must be `false` in production (enforced on boot) |
| `APP_URL` | Yes | `https://socialmesh.ebintech.in` | Public canonical HTTPS URL |
| `OCTANE_SERVER` | Prod | `frankenphp` | Octane application server driver |
| `OCTANE_HTTPS` | Prod | `true` | Generate HTTPS URLs behind proxy/tunnel |
| `TRUSTED_PROXIES` | Prod | `*` | Trust Cloudflare / reverse-proxy forwarding headers |
| `SESSION_SECURE_COOKIE` | Prod | `true` | Restrict session cookies to HTTPS |
| `INSTANCE_REGISTRATIONS_ENABLED` | No | `false` | Block public signups after the first instance owner registers |
| `SELF_HOSTED` | No | `true` | Disable Stripe billing gate for agency self-hosted use |

## 2. Database, Queue, Cache & Workers

| Variable | Required | Default / Example | Purpose |
|---|---|---|---|
| `DB_CONNECTION` | Yes | `sqlite` | Database driver (`sqlite`, `pgsql`, `mysql`) |
| `DB_DATABASE` | Yes | `/var/www/html/database/sqlite/database.sqlite` | Path to SQLite database file |
| `CACHE_STORE` | Yes | `database` | Cache backend |
| `QUEUE_CONNECTION` | Yes | `database` | Queue backend |
| `SESSION_DRIVER` | Yes | `database` | Session storage backend |
| `QUEUE_WORKER_ENABLED` | Prod | `true` | Enable in-container queue worker |
| `QUEUE_WORKER_COUNT` | Prod | `1` | Number of queue worker processes (keep at `1` on shared VPS) |
| `QUEUE_WORKER_MEMORY_MB` | Prod | `256` | Worker memory limit in MiB |
| `SCHEDULER_ENABLED` | Prod | `true` | Run `schedule:run` every 60s inside container |
| `INERTIA_SSR_ENABLED` | Prod | `false` | Keep `false` on shared VPS to conserve RAM |

## 3. Media Storage: Cloudflare R2 & Google Drive

| Variable | Required | Default / Example | Purpose |
|---|---|---|---|
| `FILESYSTEM_DISK` | Yes | `public` or `r2` | Primary disk for media uploads (`local`, `public`, `r2`, `s3`, `google`) |
| `PUBLIC_IMAGES_DISK` | No | `r2` | Public disk for derived/publishing images |
| `MEDIA_PUBLISHING_DISK` | No | `r2` | Public HTTPS disk used to stage private Drive media for Meta |
| `MEDIA_ARCHIVE_DISK` | No | `google` | Optional archive disk (`google` mirrors originals to Google Drive) |
| `R2_ACCESS_KEY_ID` | If R2 | — | Cloudflare R2 S3 API Access Key ID |
| `R2_SECRET_ACCESS_KEY` | If R2 | — | Cloudflare R2 S3 API Secret Access Key |
| `R2_DEFAULT_REGION` | If R2 | `auto` | Cloudflare R2 region |
| `R2_BUCKET` | If R2 | `socialmesh-media` | Cloudflare R2 bucket name |
| `R2_ENDPOINT` | If R2 | `https://<account>.r2.cloudflarestorage.com` | Cloudflare R2 S3 endpoint |
| `R2_URL` | If R2 | `https://media.ebintech.in` | Public custom domain or `r2.dev` HTTPS base URL |
| `R2_USE_PATH_STYLE_ENDPOINT` | No | `true` | Path-style addressing for R2 |
| `GOOGLE_DRIVE_CLIENT_ID` | If Drive | — | Google Cloud OAuth 2.0 Client ID |
| `GOOGLE_DRIVE_CLIENT_SECRET` | If Drive | — | Google Cloud OAuth 2.0 Client Secret |
| `GOOGLE_DRIVE_REDIRECT_URI` | If Drive | `${APP_URL}/settings/instance/storage/google/callback` | Authorized redirect URI in Google Cloud Console |
| `GOOGLE_DRIVE_REFRESH_TOKEN` | Optional | — | Optional `.env` refresh token (otherwise connected via Admin UI) |
| `GOOGLE_DRIVE_ROOT_FOLDER_ID` | No | `root` | Google Drive folder ID under which client/campaign folders are created |
| `GOOGLE_DRIVE_ARCHIVE_ENABLED` | No | `false` | Set `true` to automatically archive uploaded originals to Google Drive |

## 4. Social Platform OAuth Credentials

| Variable | Platform | Callback URL |
|---|---|---|
| `FACEBOOK_CLIENT_ID` / `FACEBOOK_CLIENT_SECRET` | Facebook Pages & Instagram Business | `${APP_URL}/accounts/callback/meta` |
| `FACEBOOK_GRAPH_VERSION` | Meta Graph API | `v25.0` |
| `THREADS_CLIENT_ID` / `THREADS_CLIENT_SECRET` | Meta Threads | `${APP_URL}/accounts/callback/threads` |
| `LINKEDIN_CLIENT_ID` / `LINKEDIN_CLIENT_SECRET` | LinkedIn | `${APP_URL}/accounts/callback/linkedin` |
| `X_CLIENT_ID` / `X_CLIENT_SECRET` | X (Twitter) | `${APP_URL}/accounts/callback/x` |
| `DISCORD_CLIENT_ID` / `DISCORD_CLIENT_SECRET` / `DISCORD_BOT_TOKEN` | Discord | `${APP_URL}/accounts/callback/discord` |

## 5. Cloudflare Tunnel

| Variable | Required | Purpose |
|---|---|---|
| `TUNNEL_TOKEN` | For `docker-compose.tunnel.yaml` | Cloudflare Zero Trust tunnel token routing `socialmesh.ebintech.in` -> `http://app:8080` |
