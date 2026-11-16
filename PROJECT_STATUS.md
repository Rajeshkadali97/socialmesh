# SocialMesh — Project Status & Engineering Report

## 1. Repository & Branch Status

- **Repository**: `https://github.com/Rajeshkadali97/socialmesh`
- **Local Directory**: `/mnt/d/Ebin projects/Social mesh` (`D:\Ebin projects\SocialMesh`)
- **Base Commit**: `5720c73295a11da655d02f623b6fcf0618ba0898` (`origin/claude/ecstatic-noether-lijxh5`)
- **Active Working Branch**: `antigravity/complete-development`
- **Git Safety**:
  - `claude/ecstatic-noether-lijxh5` and its pull request have not been modified, merged, or closed.
  - No changes have been pushed to GitHub (`origin`). All work is committed locally on `antigravity/complete-development`.

## 2. Preserved & Verified Baseline Capabilities

All work from commit `5720c73295a11da655d02f623b6fcf0618ba0898` has been preserved and verified:
- **Cross-Client Overview (`/clients`)**: Displays every client workspace the user belongs to, sorted by urgency (`attention`, `empty`, `no_accounts`, `ok`), with connected account counts, 7-day publishing/scheduling counters, next scheduled post formatted in each client's own timezone, and one-click workspace switching.
- **Bulk CSV Scheduling (`/posts/bulk`)**: Supports pasting or uploading CSV/TSV spreadsheets (up to 50 rows per batch), previewing per-row validation errors, duplicate detection, SSRF-guarded image fetching, and scheduling in the client workspace's timezone.
- **Client Review & Approval Links (`/share/{token}`)**: Public share links with reviewer decision capture (`approved` / `changes_requested`), optional reviewer name and note, rate limiting, and `noindex` headers.
- **Publishing & Token Management**: Queue-backed publishing (`DispatchScheduledPosts` -> `PublishPost` -> `PublishPostTarget`) with atomic locking, idempotency guards, automatic token refresh via `TokenManager`, and explicit `missing_credentials` classification when OAuth app credentials are absent.
- **Social Platform Integrations**: Facebook Pages (Feed, Reels, Stories), Instagram Business/Creator accounts (Feed, Carousels, Reels, Stories), Threads (Feed, Carousels, Replies/Threads), LinkedIn, Bluesky, X, and Discord.

## 3. Completed Enhancements & Integrations

### A. Google Drive & Cloudflare R2 Hybrid Media Storage
- **Flysystem v3 Google Drive Adapter (`google` disk)**:
  - Implemented `App\Services\Storage\GoogleDriveTokenProvider`, `App\Services\Storage\GoogleDriveClient`, and `App\Services\Storage\GoogleDriveFilesystemAdapter` registered via `Storage::extend('google', ...)` in `AppServiceProvider`.
  - Uses minimum OAuth scope `https://www.googleapis.com/auth/drive.file`.
  - Supports automatic token refresh under an atomic cache lock (`google-drive-token-refresh`), bounded retry on HTTP 429 and 5xx responses, and explicit error classification (`missing_credentials`, `token_expired`, `quota_exceeded`, `rate_limited`, `transfer_failed`).
  - **Non-Destructive Guarantee**: Original Google Drive files are never deleted automatically (`GoogleDriveFilesystemAdapter::delete()` is a safe no-op by default, and `PostMedia::booted()` skips deletion when `disk` or `source_disk` is `google`).
- **Cloudflare R2 Publishing Disk (`r2` disk) & Staging**:
  - Added dedicated `r2` disk configuration in `config/filesystems.php` alongside `public-images`, `s3`, `public`, and `google`.
  - Updated `PublicMediaUrl` and `ImageToJpegConverter` so that when a post with `google`-stored media is published to URL-fetch platforms (Instagram, Threads), the asset is automatically staged/converted to the public publishing disk (`r2`, `public-images`, or `public`) to provide Meta with a stable, unauthenticated HTTPS URL.
- **SHA-256 Deduplication & Metadata Tracking**:
  - Added migration `2026_10_15_000001_add_campaign_and_drive_columns_to_posts_and_post_media.php` adding `drive_file_id`, `checksum` (SHA-256), `campaign`, and `storage_error` to `post_media`, and `campaign` to `posts`.
  - Updated `MediaStorageService` to compute SHA-256 checksums, prevent duplicate uploads within the same workspace, organize storage paths by workspace and campaign (`media/{workspace_id}/{campaign_slug}/...`), and optionally mirror originals to Google Drive when `MEDIA_ARCHIVE_DISK=google` (`GOOGLE_DRIVE_ARCHIVE_ENABLED=true`).
- **Admin OAuth Connection UI**:
  - Added `GoogleDriveStorageController` (`/settings/instance/storage/google/redirect`, `/callback`, `/disconnect`) and a storage status card in `resources/js/pages/settings/instance-platforms.tsx`.

### B. Multi-Client Agency Workflow & Campaign Organization
- **Campaign Tagging & Filtering**:
  - Added `campaign` support across `Post`, `PostMedia`, `DraftData`, `DraftService`, `PostView`, `PostListItem`, `PostController`, `PostDuplicator`, and `BulkScheduleImporter` (optional `campaign` CSV column).
  - Updated the Composer (`resources/js/components/compose/composer.tsx`), Posts list (`resources/js/pages/posts/index.tsx`), Post row (`resources/js/components/posts/post-row.tsx`), and Bulk Schedule page (`resources/js/pages/posts/bulk.tsx`) to edit, display, and filter by campaign.
- **13-Client Agency Seeder (`AgencyWorkspacesSeeder`)**:
  - Added `Database\Seeders\AgencyWorkspacesSeeder` provisioning Ebin Tech's 13 client workspaces with realistic timezones, posting schedules, connected accounts, and sample campaign posts for local/dev environments.

### C. Shared VPS Deployment & Backup Readiness
- Updated `docker-compose.production.yaml`, `docker-compose.tunnel.yaml`, `.env.example.prod`, and `docker/backup.sh` for strict container/volume/network isolation (`socialmesh` project, `socialmesh-app`, `socialmesh-cloudflared`, `socialmesh_storage`, `socialmesh_sqlite`, `socialmesh_net`, `mem_limit: 1g`, `cpus: 1.0`, `QUEUE_WORKER_COUNT=1`, `INERTIA_SSR_ENABLED=false`, target domain `https://socialmesh.ebintech.in`).
- Verified and deployed on live VPS target `138.252.100.181` (`server-2vf78k`, Ubuntu 24.04.5 LTS, migrated from `138.252.100.129`) with **Ebin Cloud** (`ebin_cloud` disk -> `http://ebin-cloud-app:3000/api/v1/storage/*`) as primary persistent storage and backup destination. RK Enterprises Inventory (`/opt/rk-inventory/deploy`) and Ebin Cloud (`/opt/ebin-cloud`) remain healthy and isolated.
