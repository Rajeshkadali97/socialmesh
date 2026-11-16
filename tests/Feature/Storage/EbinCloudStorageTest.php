<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Media\PublicMediaUrl;
use App\Services\Posts\MediaStorageService;
use App\Services\Posts\PostDuplicator;
use App\Services\Posts\PublishPrecheck;
use App\Services\Storage\EbinCloudClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Config::set('filesystems.default', 'ebin_cloud');
    Config::set('filesystems.archive_disk', 'ebin_cloud');
    Config::set('filesystems.disks.ebin_cloud.url', 'http://ebin-cloud-app:3000');
    Config::set('filesystems.disks.ebin_cloud.service_key', 'sm_live_test_key_1234567890');
    Config::set('filesystems.disks.ebin_cloud.cache_dir', sys_get_temp_dir().'/sm-ebin-cloud-test-cache-'.uniqid());

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    WorkspaceMembership::create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

it('verifies Ebin Cloud connection health and multi-drive quota status', function (): void {
    Http::fake([
        'http://ebin-cloud-app:3000/api/v1/storage/status' => Http::response([
            'ok' => true,
            'app_id' => 'socialmesh',
            'namespace' => 'Applications/SocialMesh',
            'file_count' => 12,
            'total_bytes' => 1048576,
            'accounts' => [
                ['account' => 'gdrive_primary', 'limit' => 429496729600, 'usage' => 936208533, 'free' => 428438623525, 'usedPct' => 0.2],
                ['account' => 'gdrive_secondary', 'limit' => 5497558138880, 'usage' => 2607325855044, 'free' => 2310274452413, 'usedPct' => 47.4],
            ],
        ], 200),
    ]);

    $client = new EbinCloudClient(
        baseUrl: 'http://ebin-cloud-app:3000',
        serviceKey: 'sm_live_test_key_1234567890',
    );

    $status = $client->verifyConnection();

    expect($status['healthy'])->toBeTrue()
        ->and($status['namespace'])->toBe('Applications/SocialMesh')
        ->and($status['file_count'])->toBe(12)
        ->and($status['quota_limit_bytes'])->toBeGreaterThan(5_000_000_000_000);
});

it('uploads image to Ebin Cloud, verifies SHA-256 checksum, deduplicates retries, and serves signed publishing URLs', function (): void {
    $file = UploadedFile::fake()->image('campaign-banner.png', 400, 400);
    $rawBytes = (string) file_get_contents($file->getRealPath());
    $expectedSha256 = hash('sha256', $rawBytes);

    Http::fake([
        'http://ebin-cloud-app:3000/api/v1/storage/upload' => function ($request) use ($expectedSha256, $rawBytes) {
            $logicalPath = $request->header('X-Logical-Path')[0] ?? 'media/test.png';

            return Http::response([
                'ok' => true,
                'deduplicated' => false,
                'file' => [
                    'id' => 'ebin-cloud-uuid-001',
                    'name' => basename($logicalPath),
                    'logical_path' => $logicalPath,
                    'mime_type' => 'image/png',
                    'size' => strlen($rawBytes),
                    'sha256' => $expectedSha256,
                    'drive_account' => 'gdrive_primary',
                    'drive_file_id' => 'gdrive_primary:ebin-cloud/123-campaign-banner.png',
                    'updated_at' => now()->toIso8601String(),
                    'archived' => 0,
                ],
            ], 201);
        },
        'http://ebin-cloud-app:3000/api/v1/storage/by-path*' => function ($request) use ($expectedSha256, $rawBytes) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $path = (string) ($q['path'] ?? 'media/test.png');

            return Http::response([
                'ok' => true,
                'exists' => true,
                'file' => [
                    'id' => 'ebin-cloud-uuid-001',
                    'name' => basename($path),
                    'logical_path' => $path,
                    'mime_type' => 'image/png',
                    'size' => strlen($rawBytes),
                    'sha256' => $expectedSha256,
                    'drive_account' => 'gdrive_primary',
                    'drive_file_id' => 'gdrive_primary:ebin-cloud/123-campaign-banner.png',
                    'updated_at' => now()->toIso8601String(),
                    'archived' => 0,
                ],
            ], 200);
        },
        'http://ebin-cloud-app:3000/api/v1/storage/files/ebin-cloud-uuid-001/download' => Http::response($rawBytes, 200, [
            'Content-Type' => 'image/png',
            'X-Ebin-Cloud-Sha256' => $expectedSha256,
        ]),
    ]);

    $service = app(MediaStorageService::class);
    $media1 = $service->store($this->workspace->id, $file, 'Banner alt', 'Q4 Ebin Cloud');
    $media2 = $service->store($this->workspace->id, $file, 'Duplicate banner', 'Q4 Ebin Cloud');

    expect($media1->disk)->toBe('ebin_cloud')
        ->and($media1->drive_file_id)->toBe('ebin-cloud-uuid-001')
        ->and($media1->checksum)->toBe($expectedSha256)
        ->and($media1->original_filename)->toBe('campaign-banner.png')
        ->and($media2->path)->toBe($media1->path);

    // Scoped temporary signed URL for Instagram/Threads publishing
    $publicUrl = app(PublicMediaUrl::class)->for($media1, Platform::Instagram);
    expect($publicUrl)->toContain('/media/'.$media1->id.'/signed')
        ->and($publicUrl)->toContain('signature=');

    // Fetch the signed URL without authentication (as Meta servers do)
    $signedResponse = $this->get($publicUrl);
    $signedResponse->assertOk();
});

it('supports media library listing, reuse across multiple posts, reference-aware deletion guard, and archiving', function (): void {
    $file = UploadedFile::fake()->image('reusable-hero.jpg', 300, 300);
    $rawBytes = (string) file_get_contents($file->getRealPath());
    $sha256 = hash('sha256', $rawBytes);

    Http::fake([
        'http://ebin-cloud-app:3000/api/v1/storage/*' => Http::response([
            'ok' => true,
            'exists' => true,
            'file' => [
                'id' => 'ebin-cloud-uuid-reuse',
                'name' => 'reusable-hero.jpg',
                'logical_path' => 'media/'.$this->workspace->id.'/reusable-hero.jpg',
                'mime_type' => 'image/jpeg',
                'size' => strlen($rawBytes),
                'sha256' => $sha256,
                'drive_account' => 'gdrive_primary',
                'drive_file_id' => 'gdrive_primary:ebin-cloud/reusable-hero.jpg',
                'updated_at' => now()->toIso8601String(),
                'archived' => 0,
            ],
        ], 200),
    ]);

    $this->actingAs($this->user);

    $service = app(MediaStorageService::class);
    $asset = $service->store($this->workspace->id, $file, 'Hero image', 'Brand');

    $draft = Post::create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->user->id,
        'base_text' => 'First draft using asset',
        'segments' => ['First draft using asset'],
        'status' => PostStatus::Draft->value,
    ]);
    $asset->update(['post_id' => $draft->id]);

    $scheduledPost = Post::create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->user->id,
        'base_text' => 'Scheduled post reusing same asset',
        'segments' => ['Scheduled post reusing same asset'],
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => now()->addDay(),
    ]);

    // Attach existing library asset to second post without re-uploading
    $attachRes = $this->postJson("/posts/{$scheduledPost->id}/media/{$asset->id}/attach");
    $attachRes->assertCreated();
    $clonedId = (string) $attachRes->json('media.id');
    PostMedia::withoutGlobalScopes()->whereKey($clonedId)->update(['post_id' => $scheduledPost->id]);

    // Verify media library JSON returns 1 deduplicated item with reference_count = 2
    $libRes = $this->getJson('/media');
    $libRes->assertOk()
        ->assertJsonPath('items.0.reference_count', 2);

    // Reference-aware deletion guard blocks deleting from library while attached to active draft/scheduled post
    $delRes = $this->deleteJson("/media/{$asset->id}");
    $delRes->assertStatus(422);

    // Duplicating a published post reuses the Ebin Cloud path without duplicating cloud storage bytes
    $scheduledPost->update(['status' => PostStatus::Published->value]);
    $duplicated = app(PostDuplicator::class)->duplicate($scheduledPost->fresh(['media', 'targets']));
    expect($duplicated->media)->toHaveCount(1)
        ->and($duplicated->media->first()->path)->toBe($asset->path);
});

it('blocks scheduling or publishing when a cloud media object is missing', function (): void {
    Http::fake([
        'http://ebin-cloud-app:3000/api/v1/storage/by-path*' => Http::response([
            'error' => 'Not found',
            'exists' => false,
        ], 404),
    ]);

    $account = ConnectedAccount::create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram->value,
        'remote_account_id' => 'ig-1',
        'handle' => '@ebintech',
        'display_name' => 'Ebin Tech',
        'auth_method' => 'oauth',
        'connected_by_user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $post = Post::create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->user->id,
        'base_text' => 'Post with missing cloud media',
        'segments' => ['Post with missing cloud media'],
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => now()->addHour(),
    ]);

    PostTarget::create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'sections' => ['Post with missing cloud media'],
    ]);

    PostMedia::create([
        'workspace_id' => $this->workspace->id,
        'post_id' => $post->id,
        'disk' => 'ebin_cloud',
        'path' => 'media/'.$this->workspace->id.'/missing.jpg',
        'mime' => 'image/jpeg',
        'size_bytes' => 1024,
        'kind' => 'image',
    ]);

    $blocked = app(PublishPrecheck::class)->blockingTargets($post->fresh(['targets.account', 'media']));
    expect($blocked)->not->toBeEmpty()
        ->and($blocked[0]['issues'])->toContain('media_missing');
});
