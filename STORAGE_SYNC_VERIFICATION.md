# Storage Synchronization & Compatibility Verification Report

## 1. End-to-End Media Lifecycle Verification

SocialMesh implements a 12-stage media synchronization lifecycle across local/R2 primary storage and Google Drive archival storage:

| Stage | Component | Implementation & Verification |
|---|---|---|
| **1. Upload** | `PostMediaController::store` / `PostVideoUploadController` / `PostImageEditController` / `PostGifController` | Receives multipart image, presigned/streamed video, beautified image, or Klipy GIF/clip. |
| **2. Validation** | `StorePostMediaRequest` / `StoresPresignedVideo` / `SafeImageFetcher` | Enforces MIME whitelist, file size ceiling, pixel bomb guard (`16M` pixels), and MP4 ISO-BMFF `ftyp` header sniff. |
| **3. Primary & Archive Storage** | `MediaStorageService::store` / `syncGoogleDriveMetadata` | Writes to `FileStorage::diskName()` (`public` / `r2` / `google`) and mirrors original bytes to Google Drive when `MEDIA_ARCHIVE_DISK=google`. |
| **4. Workspace Isolation** | `PostMedia` (`HasWorkspaceScope`) + route binding | Every row is scoped to `workspace_id`; cross-workspace access returns HTTP `404`. |
| **5. Campaign Organization** | `MediaStorageService::mediaDirectory` | Stores files under `media/{workspace_id}/{campaign_slug}/{uuid}.{ext}` (and inside `SocialMesh/media/{workspace_id}/{campaign_slug}` on Google Drive). |
| **6. Database Metadata** | `post_media` table | Persists `disk`, `path`, `source_disk`, `source_path`, `drive_file_id`, `checksum` (SHA-256), `campaign`, `mime`, `size_bytes`, `width`, `height`, `duration_seconds`, and `storage_error`. |
| **7. Retrieval & Preview** | `PostMedia::url()` + `PostMediaContentController::show` (`/media/{media}/raw`) | Public disks use direct URLs; `google`-disk assets and canvas editors use the authenticated same-origin `/media/{media}/raw` proxy route with automatic fallback to `source_disk = google`. |
| **8. Media Reuse & Duplication** | `MediaStorageService::findDuplicate` + `PostDuplicator::duplicate` | Identical uploads within a workspace reuse the existing `drive_file_id` via SHA-256 `checksum`. Post duplication preserves `campaign`, `checksum`, `drive_file_id`, and directory structure. |
| **9. Publishing Preparation** | `PublishPostTarget` -> Connector | Loads attached `PostMedia` items and resolves per-segment placements via `SegmentMediaResolver`. |
| **10. Publishing Storage Staging** | `PublicMediaUrl::for` + `ImageToJpegConverter::convert` | When an asset resides on `google` (or needs format conversion for Meta), stages/converts the file onto `FileStorage::publishingDiskName()` (`r2` / `public-images` / `public`) at `published/{path}`. |
| **11. Accessible Publishing URL** | `InstagramConnector` / `ThreadsConnector` / `FacebookDirectMessageConnector` | Passes the absolute HTTPS URL from `PublicMediaUrl` to Meta's Graph API, or streams bytes via `Storage::disk($media->disk)` for byte-upload connectors (`FacebookConnector`, `LinkedInConnector`, `XConnector`, `BlueskyPublishConnector`, `DiscordPublishConnector`). |
| **12. Non-Destructive Cleanup** | `PostMedia::booted` + `PruneAbandonedUploads` | `PruneAbandonedUploads` cleans `tmp/media/` and 6h+ unattached orphans; `PostMedia::deleting` **never** deletes files where `disk === 'google'` or `source_disk === 'google'`. |

---

## 2. Application-Wide Storage Compatibility Matrix

| Feature / Surface | Primary Disk Used | Google Drive Support | R2 / Public Staging Support | Temp Local Cleanup |
|---|---|---|---|---|
| **Post Composer Image Upload** (`PostMediaController`) | `FileStorage::diskName()` | Full (`disk=google` or `source_disk=google` archive) | Yes (`PublicMediaUrl` / `ImageToJpegConverter`) | PHP upload temp cleaned after request |
| **Image Beautifier / Editor** (`PostImageEditController`) | `FileStorage::diskName()` | Full (syncs composed image to Drive; proxies via `/media/{media}/raw`) | Yes | No local temp files retained |
| **Direct Video Upload** (`StoresPresignedVideo`, `StreamedUploadController`) | `FileStorage::diskName()` | Full (uses `uploads.stream` signed route when driver is `google` or `local`) | Presigned S3/R2 PUT when disk is `s3`/`r2` | `php://temp` closed in `finally`; `PruneAbandonedUploads` cleans `tmp/media/` |
| **GIF / Sticker / Clip Attach** (`GifAttacher`) | `FileStorage::diskName()` | Full (`storeFromUrl` syncs to Drive) | Yes | `ffprobe` temp file unlinked in `finally` |
| **Bulk CSV Schedule** (`BulkScheduleImporter`) | `FileStorage::diskName()` | Full (`storeFromUrl` with `campaign` column) | Yes | In-memory fetch |
| **Post Duplication & Sync Fan-Out** (`PostDuplicator`, `SyncFanOutService`) | `media->disk` | Full (preserves `drive_file_id`, `campaign`, directory prefix) | Yes | Rollback deletes orphaned copies (no-op on `google`) |
| **Instagram & Threads Publishing** (`InstagramConnector`, `ThreadsConnector`) | `PublicMediaUrl` | Stages `google` files to `FileStorage::publishingDiskName()` (`r2`/`public`) | Native | Derived JPEG cleaned when post deleted |
| **Facebook / LinkedIn / X / Bluesky / Discord Publishing** | `Storage::disk($media->disk)` | Reads bytes/streams directly from `google` or primary disk | Native | `GifToMp4Converter` deletes `storage/app/tmp/gif-to-mp4/{uuid}` in `finally` |
| **User Avatars & Workspace Logos** (`ProfileController`, `WorkspaceSettingsController`) | `FileStorage::publicImageDiskName()` | Uses public image disk (`public` / `r2` / `public-images`) | Native | Old avatar/logo deleted on replacement |
