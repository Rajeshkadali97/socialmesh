# SocialMesh — Known Platform & Operational Limitations

This document records verified API constraints and intentional architectural boundaries so operators and agency managers know what each platform supports.

## 1. Social Platform API Limitations

### Instagram Business / Creator (Meta Graph API v25.0)
- **Account Type**: Personal Instagram profiles are not supported by the Meta Content Publishing API. Accounts must be Instagram Professional (Business or Creator) linked to a Facebook Page.
- **Media Required**: Instagram does not support text-only posts. Every Feed post, Carousel, Reel, or Story must include at least one image or video.
- **Public HTTPS Media URLs**: Meta's servers fetch media asynchronously from a public HTTPS URL (`PublicMediaUrl`). Private localhost URLs will fail when publishing to real Instagram accounts unless served through a public tunnel (`socialmesh.ebintech.in`) or Cloudflare R2 (`r2` / `public-images` disk).
- **Stories Captions**: Instagram's Content Publishing API does not render a text caption on Stories (`PostFormat::Story` strips captions and publishes the visual asset).
- **Image Aspect Ratios & Formats**: Unsupported formats (such as WebP or PNG) are automatically converted to JPEG (`ImageToJpegConverter`) before handing the URL to Meta.

### Facebook Pages (Meta Graph API v25.0)
- **Pages Only**: Meta's Graph API supports publishing to Facebook Pages (`pages_manage_posts`, `pages_read_engagement`), not personal Facebook user timelines.
- **Token Expiry**: Long-lived Meta user tokens expire after ~60 days; `TokenManager` and `RefreshExpiringTokens` refresh them automatically when `FACEBOOK_CLIENT_ID` and `FACEBOOK_CLIENT_SECRET` are configured.

### Meta Threads (Threads Graph API)
- **Separate OAuth App / Surface**: Threads uses `threads.net` for authorization and `graph.threads.net` for token exchange and publishing (`THREADS_CLIENT_ID` / `THREADS_CLIENT_SECRET`).
- **Character & Carousel Limits**: Maximum 500 characters per post and up to 20 items per carousel. Like Instagram, Threads requires publicly reachable HTTPS URLs for attached media.

### LinkedIn
- **Community Management Scope Gate**: Personal profile posting (`w_member_social`) works with standard LinkedIn apps. Organization Page posting and comment inbox polling (`w_organization_social`, `r_organization_social`) require LinkedIn's restricted Community Management API approval and are gated behind the **LinkedIn Pages & engagement inbox** toggle in *Instance Settings > Platforms*.

### Bluesky
- **App Passwords**: Bluesky accounts connect via AT Protocol handle + App Password (`auth_method = app_password`), not OAuth2.
- **GIF & Video Mixing**: Bluesky treats animated GIFs as videos and allows at most one video/GIF per post, unmixed with static images (enforced in both the composer precheck and `PublishPrecheck`).

## 2. Bulk CSV Schedule Limitations

- **Batch Size**: Up to 50 rows per import (`BulkScheduleImporter::MAX_ROWS = 50`) to bound synchronous image downloads and request time.
- **Video Links**: Bulk CSV import supports `post` and `story` image URLs (`https://`), but video Reels must be uploaded through the Composer so video duration, codec, and aspect ratio can be validated client- and server-side.

## 3. Google Drive Storage Boundaries

- **Non-Destructive Archive**: Deleting a post or media attachment in SocialMesh removes local/R2 copies but **intentionally never deletes** the original file in Google Drive. Space reclamation in Google Drive is managed manually by the Drive owner.
- **Not a Direct CDN for Meta**: Because Google Drive files require OAuth bearer tokens or redirect pages, SocialMesh automatically stages Google Drive media onto the configured public publishing disk (`r2`, `public-images`, or `public`) when publishing to Instagram or Threads.
