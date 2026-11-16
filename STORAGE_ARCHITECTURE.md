# SocialMesh — Media Storage Architecture

## 1. Architectural Decision: Why Hybrid Storage is Required

Social platforms such as **Meta (Instagram Business & Threads)** publish media by receiving a **publicly reachable HTTPS URL** (`image_url` / `video_url`) that Meta's servers fetch asynchronously from their own infrastructure.

- Private Google Drive links, OAuth-gated download endpoints, or short-lived browser session URLs **cannot** be fetched reliably by Meta's crawlers.
- Local VPS storage alone risks filling up the shared VPS disk over time as 13 client workspaces upload campaign creatives.

Therefore, SocialMesh implements a **4-tier Ebin Cloud-backed media architecture**:

| Tier | Technology | Role |
|---|---|---|
| **1. Primary Persistent Storage & Backups** | **Ebin Cloud** (`ebin_cloud` disk -> `http://ebin-cloud-app:3000/api/v1/storage/*`, backed by 3 Google Drive accounts totaling `~10.9 TB` via `rclone`) | Primary durable storage for all uploaded images, videos, draft attachments, scheduled-post media, reusable media library assets, and SQLite database backups under namespace `Applications/SocialMesh/`. |
| **2. Scoped Publishing Delivery** | Signed Expiring Route (`/media/{media}/signed`) or Cloudflare R2 (`r2` disk) | Keeps Ebin Cloud private by default while issuing scoped 2-hour HMAC-signed HTTPS URLs (`https://socialmesh.ebintech.in/media/{media}/signed?expires=...&signature=...`) for Meta (`InstagramConnector`, `ThreadsConnector`). |
| **3. Metadata, Lifecycle & Relationships** | SocialMesh SQLite Database (`post_media` & `post_media_placements`) | Tracks `disk`, `path`, `original_filename`, `uploaded_by_user_id`, `drive_file_id` (Ebin Cloud UUID), SHA-256 `checksum`, `upload_status`, `processing_status`, `archived_at`, `deleted_at`, and reference counts across posts. |
| **4. Bounded Local Cache & Ephemeral Processing** | VPS Temporary Memory & Bounded Cache (`storage/app/cache/ebin-cloud`, capped at `256 MB`) | Performs bounded read-through caching with automatic LRU eviction and ephemeral image/GIF conversion (`ImageToJpegConverter`, `GifToMp4Converter`) with immediate cleanup. |

## 2. Data Flow

### Upload Flow (`MediaStorageService`)
1. User uploads an image (or imports an `image_url` via Bulk CSV Schedule) in workspace `W` with optional campaign `C`.
2. `MediaStorageService` computes the SHA-256 `checksum`.
3. **Deduplication Check**: If an identical `checksum` already exists in workspace `W` on the target disk, SocialMesh reuses the stored path and `drive_file_id` without re-uploading duplicate bytes.
4. **Primary Storage**: The file is written to `FILESYSTEM_DISK` (e.g., `r2` or `public`) under `media/{workspace_id}/{campaign_slug}/{filename}`.
5. **Google Drive Archive Sync**: When `MEDIA_ARCHIVE_DISK=google` (`GOOGLE_DRIVE_ARCHIVE_ENABLED=true`), SocialMesh uploads the original bytes to Google Drive, stores `drive_file_id`, and sets `source_disk = 'google'`, `source_path = ...`. If Google Drive is temporarily unreachable or out of quota, the error message is recorded in `post_media.storage_error` while keeping the post's primary publishing copy intact.

### Publishing Flow (`PublicMediaUrl` & Connectors)
1. **Byte-Upload Platforms** (`FacebookConnector`, `LinkedInConnector`, `BlueskyConnector`, `XConnector`, `DiscordConnector`): Read the media bytes via `FileStorage::disk($media->disk)` and upload them directly to the platform API.
2. **URL-Fetch Platforms** (`InstagramConnector`, `ThreadsConnector`): Call `PublicMediaUrl::for($media, $platform)`:
   - If the image format requires conversion (e.g., WebP/PNG to JPEG for Instagram), `ImageToJpegConverter` encodes a derived `.jpg` onto `FileStorage::publishingDiskName()` (`r2` / `public-images` / `public`).
   - If `$media->disk === 'google'` (stored only on Google Drive), `PublicMediaUrl` automatically stages a copy to `published/{path}` on `FileStorage::publishingDiskName()` (`r2` or `public`) and returns its absolute HTTPS URL.

## 3. Preserving Existing Media References

- Existing `post_media` rows using `local`, `public`, `s3`, or `public-images` continue to work without modification.
- Both `disk` (active/composed asset) and `source_disk` (pre-edit or archived original) are preserved.
