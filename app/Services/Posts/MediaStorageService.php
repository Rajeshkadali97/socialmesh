<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Models\PostMedia;
use App\Services\Storage\GoogleDriveFilesystemAdapter;
use App\Support\FileStorage;
use App\Support\SafeImageFetcher;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MediaStorageService
{
    public function __construct(private readonly SafeImageFetcher $fetcher) {}

    /**
     * Store an uploaded image on the configured disk and create an orphan PostMedia row.
     */
    public function store(string $workspaceId, UploadedFile $file, ?string $altText = null, ?string $campaign = null): PostMedia
    {
        $realPath = $file->getRealPath();
        $checksum = is_string($realPath) && $realPath !== '' && is_file($realPath)
            ? (hash_file('sha256', $realPath) ?: null)
            : null;
        $normalizedCampaign = $this->normalizeCampaign($campaign);
        $directory = $this->mediaDirectory($workspaceId, $normalizedCampaign);
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();
        $dimensions = is_string($realPath) && $realPath !== '' ? (@getimagesize($realPath) ?: [null, null]) : [null, null];

        $disk = FileStorage::diskName();
        $duplicate = $checksum !== null ? $this->findDuplicate($workspaceId, $checksum, $disk) : null;

        if ($duplicate instanceof PostMedia) {
            $path = $duplicate->path;
            $driveFileId = $duplicate->drive_file_id;
            $sourceDisk = $duplicate->source_disk;
            $sourcePath = $duplicate->source_path;
            $storageError = null;
        } else {
            $storedPath = $file->store($directory, $disk);
            if (! is_string($storedPath)) {
                throw new RuntimeException('Failed to store uploaded media file.');
            }
            $path = $storedPath;
            [$driveFileId, $sourceDisk, $sourcePath, $storageError] = $this->syncGoogleDriveMetadata(
                workspaceId: $workspaceId,
                primaryDisk: $disk,
                primaryPath: $path,
                checksum: $checksum,
                mime: $mime,
                bytes: is_string($realPath) && $realPath !== '' && is_file($realPath) ? ((string) file_get_contents($realPath)) : null,
            );
        }

        return PostMedia::create([
            'workspace_id' => $workspaceId,
            'post_id' => null,
            'disk' => $disk,
            'path' => $path,
            'source_disk' => $sourceDisk,
            'source_path' => $sourcePath,
            'drive_file_id' => $driveFileId,
            'checksum' => $checksum,
            'campaign' => $normalizedCampaign,
            'storage_error' => $storageError,
            'kind' => 'image',
            'mime' => $mime,
            'size_bytes' => $size,
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt_text' => $altText,
            'position' => 0,
        ]);
    }

    /**
     * Download an image from a public URL (SSRF-guarded) and store it as an orphan
     * PostMedia row, mirroring store().
     *
     * @throws RuntimeException if the URL is blocked or the response is not a valid image.
     */
    public function storeFromUrl(string $workspaceId, string $url, ?string $altText = null, ?string $campaign = null): PostMedia
    {
        $image = $this->fetcher->fetch($url);
        $bytes = $image['bytes'];
        $checksum = hash('sha256', $bytes);
        $normalizedCampaign = $this->normalizeCampaign($campaign);
        $directory = $this->mediaDirectory($workspaceId, $normalizedCampaign);

        $extension = match ($image['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };

        $disk = FileStorage::diskName();
        $duplicate = $this->findDuplicate($workspaceId, $checksum, $disk);

        if ($duplicate instanceof PostMedia) {
            $path = $duplicate->path;
            $driveFileId = $duplicate->drive_file_id;
            $sourceDisk = $duplicate->source_disk;
            $sourcePath = $duplicate->source_path;
            $storageError = null;
        } else {
            $path = $directory.'/'.Str::uuid()->toString().'.'.$extension;
            FileStorage::disk($disk)->put($path, $bytes);
            [$driveFileId, $sourceDisk, $sourcePath, $storageError] = $this->syncGoogleDriveMetadata(
                workspaceId: $workspaceId,
                primaryDisk: $disk,
                primaryPath: $path,
                checksum: $checksum,
                mime: $image['mime'],
                bytes: $bytes,
            );
        }

        $dimensions = @getimagesizefromstring($bytes) ?: [null, null];

        return PostMedia::create([
            'workspace_id' => $workspaceId,
            'post_id' => null,
            'disk' => $disk,
            'path' => $path,
            'source_disk' => $sourceDisk,
            'source_path' => $sourcePath,
            'drive_file_id' => $driveFileId,
            'checksum' => $checksum,
            'campaign' => $normalizedCampaign,
            'storage_error' => $storageError,
            'kind' => 'image',
            'mime' => $image['mime'],
            'size_bytes' => strlen($bytes),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt_text' => $altText,
            'position' => 0,
        ]);
    }

    /**
     * Store a beautified image: the composed image becomes the post's media,
     * the original source is retained for non-destructive re-editing.
     *
     * @param  array<string, mixed>  $settings
     */
    public function storeBeautified(string $workspaceId, UploadedFile $composed, UploadedFile $source, array $settings, ?string $altText = null, ?string $campaign = null): PostMedia
    {
        $disk = FileStorage::diskName();
        $normalizedCampaign = $this->normalizeCampaign($campaign);
        $directory = $this->mediaDirectory($workspaceId, $normalizedCampaign);
        $storedPath = $composed->store($directory, $disk);
        $storedSourcePath = $source->store($directory, $disk);
        if (! is_string($storedPath) || ! is_string($storedSourcePath)) {
            throw new RuntimeException('Failed to store beautified media files.');
        }
        $path = $storedPath;
        $sourcePath = $storedSourcePath;

        $realPath = $composed->getRealPath();
        $checksum = is_string($realPath) && $realPath !== '' && is_file($realPath)
            ? (hash_file('sha256', $realPath) ?: null)
            : null;
        $dimensions = is_string($realPath) && $realPath !== '' ? (@getimagesize($realPath) ?: [null, null]) : [null, null];
        $mime = (string) $composed->getMimeType();
        [$driveFileId, , , $storageError] = $this->syncGoogleDriveMetadata(
            workspaceId: $workspaceId,
            primaryDisk: $disk,
            primaryPath: $path,
            checksum: $checksum,
            mime: $mime,
            bytes: is_string($realPath) && $realPath !== '' && is_file($realPath) ? ((string) file_get_contents($realPath)) : null,
        );

        return PostMedia::create([
            'workspace_id' => $workspaceId,
            'post_id' => null,
            'disk' => $disk,
            'path' => $path,
            'kind' => 'image',
            'source_disk' => $disk,
            'source_path' => $sourcePath,
            'drive_file_id' => $driveFileId,
            'checksum' => $checksum,
            'campaign' => $normalizedCampaign,
            'storage_error' => $storageError,
            'edit_settings' => $settings,
            'mime' => $mime,
            'size_bytes' => $composed->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt_text' => $altText,
            'position' => 0,
        ]);
    }

    /**
     * Replace the composed file + settings of an existing beautified media, keeping its source.
     *
     * @param  array<string, mixed>  $settings
     */
    public function replaceBeautified(PostMedia $media, UploadedFile $composed, array $settings, ?string $altText = null): PostMedia
    {
        // Store the new file and commit the row before deleting the old file, so a
        // failed store never leaves the row pointing at a now-missing path.
        $oldPath = $media->path;
        $directory = $this->mediaDirectory($media->workspace_id, $media->campaign);
        $storedPath = $composed->store($directory, $media->disk);
        if (! is_string($storedPath)) {
            throw new RuntimeException('Failed to store updated media file.');
        }
        $path = $storedPath;
        $realPath = $composed->getRealPath();
        $checksum = is_string($realPath) && $realPath !== '' && is_file($realPath)
            ? (hash_file('sha256', $realPath) ?: null)
            : $media->checksum;
        $dimensions = is_string($realPath) && $realPath !== '' ? (@getimagesize($realPath) ?: [null, null]) : [null, null];
        $mime = (string) $composed->getMimeType();
        [$driveFileId, , , $storageError] = $this->syncGoogleDriveMetadata(
            workspaceId: $media->workspace_id,
            primaryDisk: $media->disk,
            primaryPath: $path,
            checksum: $checksum,
            mime: $mime,
            bytes: is_string($realPath) && $realPath !== '' && is_file($realPath) ? ((string) file_get_contents($realPath)) : null,
        );

        $media->update([
            'path' => $path,
            'checksum' => $checksum,
            'drive_file_id' => $driveFileId ?? $media->drive_file_id,
            'storage_error' => $storageError,
            'edit_settings' => $settings,
            'mime' => $mime,
            'size_bytes' => $composed->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt_text' => $altText,
        ]);

        if ($oldPath !== $path && $media->disk !== 'google') {
            FileStorage::disk($media->disk)->delete($oldPath);
        }

        return $media->refresh();
    }

    private function normalizeCampaign(?string $campaign): ?string
    {
        if ($campaign === null) {
            return null;
        }

        $trimmed = trim($campaign);

        return $trimmed !== '' ? mb_substr($trimmed, 0, 120) : null;
    }

    private function mediaDirectory(string $workspaceId, ?string $campaign): string
    {
        if ($campaign === null) {
            return 'media/'.$workspaceId;
        }

        $slug = Str::slug($campaign);

        return $slug !== ''
            ? 'media/'.$workspaceId.'/'.$slug
            : 'media/'.$workspaceId;
    }

    private function findDuplicate(string $workspaceId, string $checksum, string $disk): ?PostMedia
    {
        $existing = PostMedia::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('checksum', $checksum)
            ->where('disk', $disk)
            ->whereNull('edit_settings')
            ->latest('created_at')
            ->first();

        if (! $existing instanceof PostMedia) {
            return null;
        }

        try {
            if (! FileStorage::disk($disk)->exists($existing->path)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $existing;
    }

    /**
     * Resolve or mirror Google Drive metadata for a stored asset.
     *
     * - When the primary disk is `google`, looks up the Drive file ID from the adapter.
     * - When `archive_disk` is `google` (hybrid R2/local + Google Drive archive),
     *   mirrors the original asset to Google Drive (reusing an existing Drive file ID
     *   if an identical checksum was already archived in the workspace) and records
     *   any transfer failure in `storage_error` without aborting the local/R2 upload.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null}
     */
    private function syncGoogleDriveMetadata(
        string $workspaceId,
        string $primaryDisk,
        string $primaryPath,
        ?string $checksum,
        string $mime,
        ?string $bytes,
    ): array {
        if ($primaryDisk === 'google') {
            return [$this->resolveDriveFileId($primaryPath), null, null, null];
        }

        if (FileStorage::archiveDiskName() !== 'google') {
            return [null, null, null, null];
        }

        if ($checksum !== null) {
            $archivedDuplicate = PostMedia::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('checksum', $checksum)
                ->whereNotNull('drive_file_id')
                ->latest('created_at')
                ->first();

            if ($archivedDuplicate instanceof PostMedia) {
                return [
                    $archivedDuplicate->drive_file_id,
                    'google',
                    $archivedDuplicate->source_path ?? $archivedDuplicate->path,
                    null,
                ];
            }
        }

        if ($bytes === null || $bytes === '') {
            return [null, null, null, null];
        }

        try {
            $driveStorage = FileStorage::disk('google');
            if ($driveStorage instanceof LaravelFilesystemAdapter && $driveStorage->getAdapter() instanceof GoogleDriveFilesystemAdapter) {
                $meta = $driveStorage->getAdapter()->getClient()->upload($primaryPath, $bytes, $mime);

                return [$meta['id'], 'google', $primaryPath, null];
            }

            $driveStorage->put($primaryPath, $bytes);

            return [$this->resolveDriveFileId($primaryPath), 'google', $primaryPath, null];
        } catch (Throwable $e) {
            return [null, null, null, $e->getMessage()];
        }
    }

    private function resolveDriveFileId(string $path): ?string
    {
        try {
            $storage = FileStorage::disk('google');
            if ($storage instanceof LaravelFilesystemAdapter && $storage->getAdapter() instanceof GoogleDriveFilesystemAdapter) {
                $meta = $storage->getAdapter()->getClient()->metadata($path);

                return $meta['id'] ?? null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
