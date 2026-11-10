# Cloudflare R2 Publishing Storage Setup Guide

## Why Cloudflare R2 is Used Alongside Google Drive

- **Google Drive (`google` disk)** stores private original client media organized by workspace and campaign for long-term archival without consuming VPS disk space.
- **Meta Graph API (Instagram & Threads)** requires a **publicly reachable HTTPS URL** (`image_url` / `video_url`) that Meta's servers fetch directly at publish time. Private Google Drive links or OAuth-protected endpoints fail Meta's crawler.
- **Cloudflare R2 (`r2` disk)** provides S3-compatible object storage with zero egress fees and stable public HTTPS delivery.

*(Note: When `FILESYSTEM_DISK=public` is used behind the Cloudflare Tunnel at `https://socialmesh.ebintech.in`, SocialMesh can also serve staged publishing files from `/storage/published/...` directly over HTTPS. Configuring Cloudflare R2 offloads this media storage and bandwidth from the 19 GB VPS disk.)*

---

## 1. Create the Cloudflare R2 Bucket

1. Log in to the [Cloudflare Dashboard](https://dash.cloudflare.com/) and select **R2 Object Storage**.
2. Click **Create bucket**.
3. Name the bucket: `socialmesh-media` (or your preferred name).
4. Leave Location as **Automatic**.

---

## 2. Configure Public HTTPS Access for Publishing URLs

Meta's servers must be able to `GET` objects from the bucket over HTTPS:

### Option A: Custom Domain (Recommended)
1. Open your `socialmesh-media` bucket → **Settings → Public Access → Custom Domains**.
2. Click **Connect Domain** and enter `media.ebintech.in`.
3. Cloudflare automatically creates the DNS record and TLS certificate.
4. Your `R2_URL` will be `https://media.ebintech.in`.

### Option B: Managed `r2.dev` Subdomain (Dev/Testing)
1. Under **Settings → Public Access → R2.dev subdomain**, click **Allow Access**.
2. Copy the generated `https://pub-<hash>.r2.dev` URL as your `R2_URL`.

---

## 3. Configure CORS Policy (For Browser Media Previews & Direct Video Uploads)

In the bucket **Settings → CORS Policy**, add:

```json
[
  {
    "AllowedOrigins": [
      "https://socialmesh.ebintech.in"
    ],
    "AllowedMethods": [
      "GET",
      "HEAD",
      "PUT"
    ],
    "AllowedHeaders": [
      "Content-Type",
      "Content-Length",
      "If-None-Match"
    ],
    "ExposeHeaders": [
      "ETag"
    ],
    "MaxAgeSeconds": 3600
  }
]
```

---

## 4. Create R2 S3 API Credentials

1. Go to **R2 Object Storage → Overview → Manage R2 API Tokens**.
2. Click **Create API token**.
3. Permissions: **Object Read & Write**, scoped to bucket `socialmesh-media`.
4. Copy:
   - **Access Key ID** (`R2_ACCESS_KEY_ID`)
   - **Secret Access Key** (`R2_SECRET_ACCESS_KEY`)
   - **S3 Endpoint** (`https://<ACCOUNT_ID>.r2.cloudflarestorage.com`)

---

## 5. Set Environment Variables in `.env`

```dotenv
# Use R2 for public publishing URLs while archiving originals to Google Drive:
MEDIA_PUBLISHING_DISK=r2
MEDIA_ARCHIVE_DISK=google
GOOGLE_DRIVE_ARCHIVE_ENABLED=true

R2_ACCESS_KEY_ID="your-r2-access-key-id"
R2_SECRET_ACCESS_KEY="your-r2-secret-access-key"
R2_DEFAULT_REGION="auto"
R2_BUCKET="socialmesh-media"
R2_ENDPOINT="https://<ACCOUNT_ID>.r2.cloudflarestorage.com"
R2_URL="https://media.ebintech.in"
R2_USE_PATH_STYLE_ENDPOINT=true
```

---

## 6. Verification

1. Open **Instance Settings → Platforms** (`/settings/instance/platforms`).
2. Confirm **Publishing disk (Meta HTTPS)** displays `r2 (R2 ready)` with zero configuration warnings.
3. Upload a test image to a draft post and confirm the image loads in the preview and stages cleanly.
