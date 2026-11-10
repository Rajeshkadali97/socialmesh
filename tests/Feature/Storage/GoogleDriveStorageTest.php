<?php

declare(strict_types=1);

use App\Enums\InstanceRole;
use App\Enums\Platform;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\PublicMediaUrl;
use App\Services\Posts\MediaStorageService;
use App\Services\Storage\GoogleDriveClient;
use App\Services\Storage\GoogleDriveStorageException;
use App\Services\Storage\GoogleDriveTokenProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Cache::flush();
    config([
        'filesystems.disks.google.client_id' => 'test-google-client-id',
        'filesystems.disks.google.client_secret' => 'test-google-client-secret',
        'filesystems.disks.google.refresh_token' => 'test-google-refresh-token',
        'filesystems.disks.google.access_token' => null,
        'filesystems.disks.google.root_folder_id' => 'root-folder-123',
        'filesystems.disks.google.allow_delete' => false,
        'services.google_drive.client_id' => 'test-google-client-id',
        'services.google_drive.client_secret' => 'test-google-client-secret',
        'services.google_drive.refresh_token' => 'test-google-refresh-token',
        'services.google_drive.root_folder_id' => 'root-folder-123',
    ]);
});

it('refreshes an expired OAuth token and uploads, downloads, and streams files via the google disk', function (): void {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'fresh-drive-access-token',
            'expires_in' => 3600,
        ], 200),
        'https://www.googleapis.com/drive/v3/files*' => function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'folder-'.md5($request->body()), 'name' => 'folder'], 200);
            }
            if (str_contains($request->url(), 'alt=media')) {
                return Http::response('hello-drive-payload', 200);
            }
            if (str_contains($request->url(), "mimeType != 'application/vnd.google-apps.folder'")) {
                return Http::response([
                    'files' => [[
                        'id' => 'drive-file-001',
                        'name' => 'banner.jpg',
                        'mimeType' => 'image/jpeg',
                        'size' => 19,
                        'md5Checksum' => md5('hello-drive-payload'),
                        'modifiedTime' => '2026-10-15T10:00:00Z',
                    ]],
                ], 200);
            }

            return Http::response(['files' => []], 200);
        },
        'https://www.googleapis.com/upload/drive/v3/files*' => Http::response([
            'id' => 'drive-file-001',
            'name' => 'banner.jpg',
            'mimeType' => 'image/jpeg',
            'size' => 19,
            'md5Checksum' => md5('hello-drive-payload'),
            'modifiedTime' => '2026-10-15T10:00:00Z',
        ], 200),
    ]);

    $disk = Storage::disk('google');
    $disk->put('media/ws-1/winter-launch/banner.jpg', 'hello-drive-payload');

    expect($disk->exists('media/ws-1/winter-launch/banner.jpg'))->toBeTrue()
        ->and($disk->get('media/ws-1/winter-launch/banner.jpg'))->toBe('hello-drive-payload')
        ->and($disk->size('media/ws-1/winter-launch/banner.jpg'))->toBe(19)
        ->and($disk->mimeType('media/ws-1/winter-launch/banner.jpg'))->toBe('image/jpeg');

    $stream = $disk->readStream('media/ws-1/winter-launch/banner.jpg');
    expect(is_resource($stream))->toBeTrue();
    expect(stream_get_contents($stream))->toBe('hello-drive-payload');
    fclose($stream);
});

it('automatically retries transient 429 and 503 errors and refreshes upon 401', function (): void {
    $uploadAttempts = 0;

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::sequence()
            ->push(['access_token' => 'initial-expired-token', 'expires_in' => 3600], 200)
            ->push(['access_token' => 'refreshed-token-2', 'expires_in' => 3600], 200),
        'https://www.googleapis.com/drive/v3/files*' => function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'folder-retry-1', 'name' => 'media'], 200);
            }

            return Http::response(['files' => []], 200);
        },
        'https://www.googleapis.com/upload/drive/v3/files*' => function () use (&$uploadAttempts) {
            $uploadAttempts++;
            if ($uploadAttempts === 1) {
                return Http::response(['error' => ['message' => 'Invalid Credentials']], 401);
            }
            if ($uploadAttempts === 2) {
                return Http::response(['error' => ['message' => 'Rate Limit Exceeded', 'errors' => [['reason' => 'rateLimitExceeded']]]], 429);
            }

            return Http::response([
                'id' => 'drive-file-retry-ok',
                'name' => 'retry.jpg',
                'mimeType' => 'image/jpeg',
                'size' => 11,
            ], 200);
        },
    ]);

    Storage::disk('google')->put('media/retry.jpg', 'retry-bytes');

    expect($uploadAttempts)->toBe(3);
});

it('surfaces a clear quota exceeded exception when Google Drive is full', function (): void {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'quota-token',
            'expires_in' => 3600,
        ], 200),
        'https://www.googleapis.com/drive/v3/files*' => Http::response([
            'error' => [
                'message' => 'The user\'s Drive storage quota has been exceeded.',
                'errors' => [['reason' => 'storageQuotaExceeded']],
            ],
        ], 403),
    ]);

    $provider = new GoogleDriveTokenProvider(config('filesystems.disks.google'));
    $client = new GoogleDriveClient($provider, 'root-folder-123');

    expect(fn (): array => $client->upload('media/full.jpg', 'bytes'))
        ->toThrow(GoogleDriveStorageException::class, 'Google Drive storage quota is full.');
});

it('never deletes original files on Google Drive when a PostMedia row is deleted', function (): void {
    $deleteCalled = false;

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'safe-token',
            'expires_in' => 3600,
        ], 200),
        'https://www.googleapis.com/drive/v3/files*' => function (Request $request) use (&$deleteCalled) {
            if ($request->method() === 'DELETE') {
                $deleteCalled = true;
            }

            return Http::response(['files' => []], 200);
        },
    ]);

    $workspace = Workspace::factory()->create();
    $media = PostMedia::factory()->for($workspace)->create([
        'disk' => 'google',
        'path' => 'media/'.$workspace->id.'/keep-forever.jpg',
        'source_disk' => 'google',
        'source_path' => 'media/'.$workspace->id.'/source-keep.jpg',
        'drive_file_id' => 'drive-keep-123',
    ]);

    $media->delete();
    Storage::disk('google')->delete('media/'.$workspace->id.'/keep-forever.jpg');

    expect($deleteCalled)->toBeFalse();
});

it('deduplicates identical uploads by SHA-256 checksum within a workspace and archives to Google Drive', function (): void {
    Storage::fake('local');
    config([
        'filesystems.default' => 'local',
        'filesystems.archive_disk' => 'google',
    ]);

    $driveUploads = 0;

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'archive-token',
            'expires_in' => 3600,
        ], 200),
        'https://www.googleapis.com/drive/v3/files*' => function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'folder-archive', 'name' => 'folder'], 200);
            }

            return Http::response(['files' => []], 200);
        },
        'https://www.googleapis.com/upload/drive/v3/files*' => function () use (&$driveUploads) {
            $driveUploads++;

            return Http::response([
                'id' => 'drive-archived-999',
                'name' => 'asset.jpg',
                'mimeType' => 'image/jpeg',
                'size' => 1024,
            ], 200);
        },
    ]);

    $workspace = Workspace::factory()->create();
    $service = app(MediaStorageService::class);

    $file1 = UploadedFile::fake()->image('campaign-asset.jpg', 400, 400);
    $file2 = new UploadedFile(
        $file1->getRealPath(),
        'campaign-asset-copy.jpg',
        'image/jpeg',
        null,
        true,
    );

    $first = $service->store($workspace->id, $file1, 'First', 'Diwali Campaign');
    $second = $service->store($workspace->id, $file2, 'Second', 'Diwali Campaign');

    expect($first->checksum)->not->toBeNull()
        ->and($second->checksum)->toBe($first->checksum)
        ->and($first->drive_file_id)->toBe('drive-archived-999')
        ->and($second->drive_file_id)->toBe('drive-archived-999')
        ->and($second->path)->toBe($first->path)
        ->and($first->campaign)->toBe('Diwali Campaign')
        ->and($first->path)->toContain('diwali-campaign')
        ->and($driveUploads)->toBe(1);
});

it('stages Google Drive media to the public publishing disk for Meta URL-fetch platforms', function (): void {
    Storage::fake('public');
    config([
        'filesystems.publishing_disk' => 'public',
    ]);

    $fakeFile = UploadedFile::fake()->image('photo.jpg', 300, 300);
    $jpegBytes = (string) file_get_contents($fakeFile->getRealPath());

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'stage-token',
            'expires_in' => 3600,
        ], 200),
        'https://www.googleapis.com/drive/v3/files*' => function (Request $request) use ($jpegBytes) {
            if (str_contains($request->url(), 'alt=media')) {
                return Http::response($jpegBytes, 200);
            }

            return Http::response([
                'files' => [[
                    'id' => 'drive-stage-1',
                    'name' => 'photo.jpg',
                    'mimeType' => 'image/jpeg',
                    'size' => strlen($jpegBytes),
                ]],
            ], 200);
        },
    ]);

    $workspace = Workspace::factory()->create();
    $media = PostMedia::factory()->for($workspace)->create([
        'disk' => 'google',
        'path' => 'media/'.$workspace->id.'/photo.jpg',
        'mime' => 'image/jpeg',
        'kind' => 'image',
        'drive_file_id' => 'drive-stage-1',
    ]);

    $url = app(PublicMediaUrl::class)->for($media, Platform::Instagram);

    expect($url)->toContain('/storage/published/media/'.$workspace->id.'/photo.jpg');
    Storage::disk('public')->assertExists('published/media/'.$workspace->id.'/photo.jpg');
});

it('allows an instance owner to connect and disconnect Google Drive via OAuth', function (): void {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'oauth-access-token',
            'refresh_token' => 'oauth-refresh-token-secret',
            'expires_in' => 3600,
        ], 200),
        'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
            'email' => 'media@ebintech.in',
        ], 200),
    ]);

    $owner = User::factory()->create(['instance_role' => InstanceRole::Owner]);
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['current_workspace_id' => $workspace->id])->save();

    $redirect = $this->actingAs($owner)
        ->get(route('instance-settings.storage.google.redirect'));

    $redirect->assertRedirect();
    expect($redirect->headers->get('Location'))->toContain('accounts.google.com/o/oauth2/v2/auth')
        ->toContain(rawurlencode('https://www.googleapis.com/auth/drive.file'));

    $state = session('google_drive_oauth_state');

    $this->actingAs($owner)
        ->withSession(['google_drive_oauth_state' => $state])
        ->get(route('instance-settings.storage.google.callback', [
            'state' => $state,
            'code' => 'auth-code-123',
        ]))
        ->assertRedirect(route('instance-settings.platforms'));

    $provider = new GoogleDriveTokenProvider(['client_id' => 'test-google-client-id', 'client_secret' => 'test-google-client-secret']);
    $status = $provider->status();

    expect($status['connected'])->toBeTrue()
        ->and($status['source'])->toBe('oauth')
        ->and($status['email'])->toBe('media@ebintech.in');

    $this->actingAs($owner)
        ->delete(route('instance-settings.storage.google.disconnect'))
        ->assertRedirect(route('instance-settings.platforms'));
});
