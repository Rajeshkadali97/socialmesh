# SocialMesh — Google Drive Integration Guide

## 1. Overview & Purpose

SocialMesh integrates with **Google Drive REST API v3** as an external media library and long-term client/campaign asset storage backend so that high-resolution client creatives do not exhaust local VPS disk space.

## 2. Security & OAuth Scope

- **Minimum Scope**: `https://www.googleapis.com/auth/drive.file`
  - Grants access **only** to files and folders created or opened by SocialMesh. It does not grant read/write access to arbitrary personal or unrelated files in the connected Google Drive account.
- **Secret Protection**:
  - `GOOGLE_DRIVE_CLIENT_SECRET` and `GOOGLE_DRIVE_REFRESH_TOKEN` are never exposed to the browser, Inertia page props, or application logs.
  - When connected via the Instance Settings OAuth flow, the refresh token is encrypted at rest using `Crypt::encryptString()` inside `instance_settings` (`key = google_drive.oauth`).
- **Non-Destructive Guarantee**:
  - Original Google Drive files are **never deleted automatically**.
  - `GoogleDriveFilesystemAdapter::delete()` and `deleteDirectory()` are safe no-ops unless `allow_delete` is explicitly enabled, and `PostMedia::booted()` skips storage deletion whenever `disk === 'google'` or `source_disk === 'google'`.

## 3. Folder Hierarchy

Assets uploaded to Google Drive are organized automatically under the configured root folder (`GOOGLE_DRIVE_ROOT_FOLDER_ID`, defaulting to `root`):

```
<GOOGLE_DRIVE_ROOT_FOLDER_ID>/
└── media/
    └── <workspace_id>/
        ├── <campaign-slug>/
        │   └── <uuid>.jpg
        └── <uuid>.png
```

Each uploaded file also carries Drive `appProperties`:
- `socialmesh_path`: logical path inside SocialMesh
- `sha256`: SHA-256 content checksum

## 4. Configuring Google Cloud OAuth Credentials

1. Open the [Google Cloud Console](https://console.cloud.google.com/).
2. Enable the **Google Drive API** under *APIs & Services > Library*.
3. Under *APIs & Services > OAuth consent screen*, add the scope `https://www.googleapis.com/auth/drive.file`.
4. Under *APIs & Services > Credentials*, create an **OAuth 2.0 Client ID** (Web application):
   - **Authorized redirect URI**:
     - Production: `https://socialmesh.ebintech.in/settings/instance/storage/google/callback`
     - Local: `http://localhost:8000/settings/instance/storage/google/callback`
5. Set the credentials in `.env`:
   ```dotenv
   GOOGLE_DRIVE_CLIENT_ID=your-google-client-id.apps.googleusercontent.com
   GOOGLE_DRIVE_CLIENT_SECRET=your-google-client-secret
   GOOGLE_DRIVE_REDIRECT_URI=${APP_URL}/settings/instance/storage/google/callback
   GOOGLE_DRIVE_ROOT_FOLDER_ID=root
   GOOGLE_DRIVE_ARCHIVE_ENABLED=true
   ```
6. Sign in to SocialMesh as an **Instance Owner**, navigate to **Instance Settings > Platforms**, and click **Connect Google Drive** (or supply `GOOGLE_DRIVE_REFRESH_TOKEN` directly in `.env`).

## 5. Error Handling, Token Refresh & Deduplication

- **Automatic Token Refresh**: `GoogleDriveTokenProvider::accessToken()` caches short-lived access tokens and refreshes expired tokens under an atomic cache lock (`google-drive-token-refresh`). If Google Drive returns `401 Unauthorized` mid-request, `GoogleDriveClient` clears the cached token, refreshes once, and retries automatically.
- **Transient Retry**: HTTP `429 Too Many Requests`, `userRateLimitExceeded`, `rateLimitExceeded`, and `5xx` responses are retried up to 3 attempts with backoff.
- **Quota & Error Visibility**: If the Google Drive account runs out of storage (`storageQuotaExceeded`), `GoogleDriveStorageException::quotaExceeded()` is raised and recorded on `post_media.storage_error`.
- **Duplicate Prevention**: `MediaStorageService` computes a SHA-256 `checksum` for every uploaded image and reuses the existing `drive_file_id` and path within the same workspace instead of re-uploading duplicate bytes.
