# SocialMesh — System Architecture

## 1. High-Level Overview

SocialMesh by Ebin Tech is a multi-client social media management platform built on:
- **Backend**: Laravel 13 (PHP 8.5), Laravel Fortify (authentication + 2FA + passkeys), Laravel Passport (OAuth2 / API keys / MCP server), Laravel Octane (FrankenPHP in production).
- **Frontend**: Inertia.js v3 + React 19 + TypeScript + Tailwind CSS v4 + TipTap rich-text composer.
- **Database, Queue & Cache**: SQLite (WAL mode) in single-container production deployments (or PostgreSQL/MySQL when scaled horizontally), with database-backed queue workers, cache, and sessions.

```
Browser (React 19 + Inertia v3)
   │
   ▼  HTTPS (Cloudflare Tunnel -> socialmesh.ebintech.in)
FrankenPHP / Laravel Octane (socialmesh-app :8080)
   ├── Web & Inertia Controllers (Workspace-scoped)
   ├── Queue Worker (PublishPost, PublishPostTarget, CaptureMetrics, RefreshExpiringTokens)
   ├── Scheduler (artisan schedule:run every minute -> posts:dispatch-scheduled)
   ├── SQLite Volume (socialmesh_sqlite)
   └── Media Storage Layer (FileStorage + MediaStorageService)
         ├── Cloudflare R2 / Public Disk (Public HTTPS URLs for Meta / Instagram / Threads)
         └── Google Drive API v3 Adapter (Long-term client/campaign asset library)
```

## 2. Multi-Client Workspace Isolation

Every agency client is represented as a `Workspace`:
- **Context Binding**: `App\Http\Middleware\WorkspaceMiddleware` resolves the authenticated user's `current_workspace_id`, verifies membership in `workspace_memberships`, and binds `workspace_id` into `Illuminate\Support\Facades\Context`.
- **Global Model Scope**: `App\Concerns\HasWorkspaceScope` automatically scopes `Post`, `PostMedia`, `ConnectedAccount`, `AccountSet`, `PostingSchedule`, `SyncPipeline`, and `WorkspaceMention` queries to the active workspace and stamps `workspace_id` on creation.
- **Route Model Binding**: Route bindings in `routes/posts.php`, `routes/accounts.php`, and `AppServiceProvider::bindWorkspaceMedia()` explicitly filter by `request()->user()?->current_workspace_id` before middleware execution so cross-workspace IDs always return `404 Not Found`.
- **Cross-Client Overview (`/clients`)**: `App\Support\ClientOverview` queries across the authenticated user's own workspace memberships in three batch queries (accounts, posts, queue slots) to surface health status (`attention`, `empty`, `no_accounts`, `ok`) across all ~13 client workspaces without N+1 queries.

## 3. Content & Campaign Lifecycle

1. **Draft Creation & Autosave**:
   - `DraftService::createDraft()` and `DraftService::updateDraft()` manage `Post` records, per-account `PostTarget` snapshots, thread `segment_breaks`, per-segment `PostMediaPlacement` rows, and optional `campaign` labels.
   - Optimistic concurrency (`expected_updated_at`) prevents stale browser tabs from overwriting newer edits (`409 Conflict`).
2. **Scheduling & Queueing**:
   - Posts can be scheduled for a specific timestamp (`PostScheduleController`), assigned to the next available recurring slot (`PostQueueController` / `NextSlotResolver`), or bulk-imported via CSV (`BulkScheduleImporter`).
   - All client-facing schedule inputs and previews use the workspace's configured `timezone` (`Workspace::$timezone`), while timestamps are stored in UTC.
3. **Publishing Pipeline**:
   - Every minute, `DispatchScheduledPosts` (`posts:dispatch-scheduled`) atomically transitions due `Scheduled` posts (`scheduled_at <= now()`) to `Publishing` and dispatches `PublishPost`.
   - `PublishPost` fans out `PublishPostTarget` jobs per target account using `WithoutOverlapping`.
   - `PublishPostTarget` claims the target atomically (`Pending` -> `Publishing`), refreshes expiring OAuth tokens via `TokenManager::ensureFresh()`, calls the platform connector (`FacebookConnector`, `InstagramConnector`, `ThreadsConnector`, `LinkedInConnector`, `BlueskyConnector`, `XConnector`, `DiscordConnector`), records `remote_id`, and reconciles the parent post status (`Published`, `Partial`, `Failed`).

## 4. Social Platform Connectors

| Platform | Connector | Supported Formats | Media Mechanism |
|---|---|---|---|
| Facebook Pages | `FacebookConnector` | Feed, Reels, Stories | Graph API v25.0 byte/URL upload |
| Instagram Business | `InstagramConnector` | Feed, Carousels, Reels, Stories | Graph API v25.0 container + `PublicMediaUrl` HTTPS fetch |
| Threads | `ThreadsConnector` | Feed, Carousels, Thread replies | Threads Graph API container + `PublicMediaUrl` HTTPS fetch |
| LinkedIn | `LinkedInConnector` | Feed posts, images, videos, org mentions | REST Posts & Images/Videos API |
| Bluesky | `BlueskyConnector` | Feed posts, threads, images, video/GIF | AT Protocol XRPC blob upload |
| X (Twitter) | `XConnector` | Posts, threads, images, chunked video | X API v2 + v1.1 media upload |
| Discord | `DiscordConnector` | Channel/Webhook messages + attachments | Discord Bot/Webhook API |
