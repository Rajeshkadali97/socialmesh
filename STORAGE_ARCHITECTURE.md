# SocialMesh — Media Storage Architecture

## 1. Architectural Decision: Why Hybrid Storage is Required

Social platforms such as **Meta (Instagram Business & Threads)** publish media by receiving a **publicly reachable HTTPS URL** (`image_url` / `video_url`) that Meta's servers fetch asynchronously from their own infrastructure.

- Private Google Drive links, OAuth-gated download endpoints, or short-lived browser session URLs **cannot** be fetched reliably by Meta's crawlers.
- Local VPS storage alone risks filling up the shared VPS disk over time as 13 client workspaces upload campaign creatives.

Therefore, SocialMesh implements a **4-tier hybrid media architecture**:

| Tier | Technology | Role |
|---|---|---|
| **1. Long-Term Archive & Library** | Google Drive (`google` disk) | Stores original client and campaign assets (`source_disk = 'google'`, `drive_file_id`), organized by workspace and campaign, never auto-deleted. |
| **2. Publishing-Ready Storage** | Cloudflare R2 (`r2` / `public-images` disk) or `public` disk | Serves stable, unauthenticated public HTTPS URLs for Meta (`InstagramConnector`, `ThreadsConnector`) and fast browser thumbnails. |
| **3. Metadata & Relationships** | SocialMesh Database (`post_media` & `post_media_placements`) | Tracks `disk`, `path`, `source_disk`, `source_path`, `drive_file_id`, `mime`, `size_bytes`, SHA-256 `checksum`, `campaign`, and `storage_error`. |
| **4. Ephemeral Processing** | VPS Temporary Memory / `/tmp` | Performs bounded image compression/conversion (`ImageCompressor`, `ImageToJpegConverter`, max 8 MiB upload cap, pixel ceiling guard) with immediate cleanup. |

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
