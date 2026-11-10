# Google Drive Setup Guide for SocialMesh

This guide walks you step-by-step through configuring Google Drive storage for SocialMesh without editing any application source code.

---

## 1. Create a Google Cloud Project & Enable the Drive API

1. Open the [Google Cloud Console](https://console.cloud.google.com/).
2. Create a new project (e.g., **`SocialMesh Storage`**) or select your existing Ebin Tech project.
3. Navigate to **APIs & Services → Library**.
4. Search for **Google Drive API** and click **Enable**.

---

## 2. Configure the OAuth Consent Screen

1. Navigate to **APIs & Services → OAuth consent screen** (or **Google Auth Platform → Branding / Audience**).
2. Choose **External** (or **Internal** if using a Google Workspace organization and only connecting an internal account).
3. Fill in the required fields:
   - **App name**: `SocialMesh by Ebin Tech`
   - **User support email**: your agency admin email
   - **Developer contact information**: your agency admin email
4. Under **Data Access / Scopes**, add:
   - `https://www.googleapis.com/auth/drive.file` *(Per-file access to files created or opened by SocialMesh — minimum required scope)*
   - `openid`
   - `email`
5. If your app is in **Testing** publishing status:
   - Add the Google account that owns the target Google Drive under **Test users**.
   - **Important**: Google expires refresh tokens after 7 days for apps in "Testing" status. Move the consent screen to **In production** (or use an **Internal** Workspace app) so the refresh token does not expire after 7 days.

---

## 3. Create OAuth 2.0 Client Credentials

1. Navigate to **APIs & Services → Credentials**.
2. Click **+ Create Credentials → OAuth client ID**.
3. Select **Application type**: **Web application**.
4. Name: `SocialMesh Production`.
5. Under **Authorized redirect URIs**, add:
   - **Production**:
     ```text
     https://socialmesh.ebintech.in/settings/instance/storage/google/callback
     ```
   - **Local Development** (if testing locally):
     ```text
     http://localhost:8000/settings/instance/storage/google/callback
     ```
6. Click **Create** and copy the **Client ID** and **Client Secret**.

---

## 4. (Optional) Create or Choose a Root Google Drive Folder

- By default, SocialMesh automatically creates or reuses a top-level folder named **`SocialMesh`** in your Google Drive root (`GOOGLE_DRIVE_ROOT_FOLDER_ID=root`, `GOOGLE_DRIVE_ROOT_FOLDER_NAME=SocialMesh`).
- If you prefer to point SocialMesh at a specific pre-existing folder in Google Drive:
  1. Open that folder in Google Drive in your browser: `https://drive.google.com/drive/folders/<FOLDER_ID>`
  2. Copy `<FOLDER_ID>` and set `GOOGLE_DRIVE_ROOT_FOLDER_ID=<FOLDER_ID>` in `.env`.

Inside the root folder, SocialMesh organizes assets automatically by workspace and campaign:
```text
SocialMesh/
└── media/
    └── {workspace_id}/
        ├── {campaign-slug}/
        │   └── {uuid}.jpg
        └── {uuid}.jpg (general workspace assets without campaign)
```

---

## 5. Set Environment Variables in `.env`

Add the following to your `.env` file (in `/opt/apps/socialmesh/.env` on the VPS or `.env` locally):

```dotenv
# Enable Google Drive long-term archive alongside local/R2 publishing storage:
GOOGLE_DRIVE_ARCHIVE_ENABLED=true
MEDIA_ARCHIVE_DISK=google

# Google OAuth Web Client credentials:
GOOGLE_DRIVE_CLIENT_ID="your-client-id.apps.googleusercontent.com"
GOOGLE_DRIVE_CLIENT_SECRET="your-client-secret"
GOOGLE_DRIVE_REDIRECT_URI="https://socialmesh.ebintech.in/settings/instance/storage/google/callback"
GOOGLE_DRIVE_ROOT_FOLDER_ID="root"
GOOGLE_DRIVE_ROOT_FOLDER_NAME="SocialMesh"
```

*(If running in Docker, reload the container environment: `docker compose -f docker-compose.production.yaml -f docker-compose.tunnel.yaml up -d`)*

---

## 6. Connect & Verify in SocialMesh Admin Settings

1. Log in to SocialMesh as an **Instance Owner**.
2. Go to **Instance Settings → Platforms** (`/settings/instance/platforms`).
3. Scroll to the **Media storage & Google Drive archive** card:
   - Click **Connect Google Drive**.
   - Sign in with your Google account and approve access.
   - You will be redirected back to `/settings/instance/platforms` with status **Connected (`your-email@domain.com`)**.
4. Click **Verify connection**:
   - SocialMesh queries the Google Drive `about` API, verifies token health, ensures the `SocialMesh` root folder exists, and displays live **Drive usage / quota** and **Verified date**.

---

## 7. Reconnecting & Disconnecting

- **Reconnect**: Click **Reconnect Google Drive** at any time on `/settings/instance/platforms` to re-run OAuth consent and rotate the encrypted refresh token stored in `instance_settings`.
- **Disconnect**: Click **Disconnect** to remove the stored OAuth token from SocialMesh. **Original files stored in Google Drive are never deleted.**
