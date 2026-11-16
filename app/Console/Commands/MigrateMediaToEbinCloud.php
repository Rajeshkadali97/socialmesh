<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PostMedia;
use App\Services\Storage\EbinCloudFilesystemAdapter;
use App\Support\FileStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\File;
use Throwable;

#[Description('Inventory, upload, SHA-256 verify, and migrate local SocialMesh media files to Ebin Cloud.')]
#[Signature('media:migrate-to-ebin-cloud {--cleanup-orphans : Remove unreferenced local test files after uploading and verifying them in Ebin Cloud}')]
class MigrateMediaToEbinCloud extends Command
{
    public function handle(): int
    {
        $ebinDisk = FileStorage::disk('ebin_cloud');
        if (! ($ebinDisk instanceof LaravelFilesystemAdapter) || ! ($ebinDisk->getAdapter() instanceof EbinCloudFilesystemAdapter)) {
            $this->error('The ebin_cloud disk is not registered.');

            return self::FAILURE;
        }

        $client = $ebinDisk->getAdapter()->getClient();
        if (! $client->isConfigured()) {
            $this->error('EBIN_CLOUD_URL and EBIN_CLOUD_SERVICE_KEY must be configured.');

            return self::FAILURE;
        }

        $manifestPath = storage_path('app/migration-manifest-ebin-cloud.json');
        $manifest = is_file($manifestPath)
            ? (json_decode((string) file_get_contents($manifestPath), true) ?: ['items' => []])
            : ['started_at' => now()->toIso8601String(), 'items' => []];

        $publicDisk = FileStorage::disk('public');
        $localFiles = $publicDisk->allFiles('media');

        $dbRows = PostMedia::withTrashed()
            ->withoutGlobalScopes()
            ->with('post:id,status,workspace_id,campaign')
            ->get();

        $migratedCount = 0;
        $verifiedBytes = 0;

        // 1. Migrate all DB-referenced PostMedia rows on local/public disk
        foreach ($dbRows as $row) {
            if ($row->disk === 'ebin_cloud' && $row->drive_file_id !== null) {
                continue;
            }

            $sourceDisk = FileStorage::disk($row->disk);
            if (! $sourceDisk->exists($row->path)) {
                continue;
            }

            $bytes = (string) $sourceDisk->get($row->path);
            $sha256 = hash('sha256', $bytes);

            try {
                $uploaded = $client->upload(
                    path: $row->path,
                    contents: $bytes,
                    mimeType: $row->mime,
                    workspaceId: $row->workspace_id,
                    category: 'media',
                    idempotencyKey: 'migrate-row-'.$row->id.'-'.$sha256,
                );

                // Download and verify SHA-256 round-trip
                $downloaded = $client->download('id:'.$uploaded['id']);
                if (hash('sha256', $downloaded) !== $sha256) {
                    $this->error("SHA-256 verification failed for PostMedia {$row->id} ({$row->path})");

                    return self::FAILURE;
                }

                $oldDisk = $row->disk;
                $row->forceFill([
                    'disk' => 'ebin_cloud',
                    'source_disk' => 'ebin_cloud',
                    'source_path' => $row->source_path ?? $row->path,
                    'drive_file_id' => $uploaded['id'],
                    'checksum' => $sha256,
                    'storage_error' => null,
                ])->save();

                if ($oldDisk !== 'ebin_cloud' && $oldDisk !== 'google') {
                    $sourceDisk->delete($row->path);
                }

                $manifest['items'][$row->path] = [
                    'path' => $row->path,
                    'post_media_id' => $row->id,
                    'post_id' => $row->post_id,
                    'post_status' => $row->post?->status->value,
                    'workspace_id' => $row->workspace_id,
                    'size_bytes' => strlen($bytes),
                    'sha256' => $sha256,
                    'ebin_cloud_id' => $uploaded['id'],
                    'verified' => true,
                    'local_removed' => true,
                    'migrated_at' => now()->toIso8601String(),
                ];

                $migratedCount++;
                $verifiedBytes += strlen($bytes);
            } catch (Throwable $e) {
                $this->error("Failed migrating {$row->path}: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        // 2. Inventory & migrate any unreferenced files in storage/app/public/media
        foreach ($localFiles as $relPath) {
            if (! $publicDisk->exists($relPath)) {
                continue;
            }

            $bytes = (string) $publicDisk->get($relPath);
            $sha256 = hash('sha256', $bytes);

            try {
                $uploaded = $client->upload(
                    path: $relPath,
                    contents: $bytes,
                    mimeType: null,
                    workspaceId: null,
                    category: 'archive',
                    idempotencyKey: 'migrate-orphan-'.hash('sha256', $relPath.':'.$sha256),
                );

                $downloaded = $client->download('id:'.$uploaded['id']);
                if (hash('sha256', $downloaded) !== $sha256) {
                    $this->error("SHA-256 verification failed for orphan file {$relPath}");

                    return self::FAILURE;
                }

                $removed = false;
                if ($this->option('cleanup-orphans')) {
                    $publicDisk->delete($relPath);
                    $removed = true;
                }

                $manifest['items'][$relPath] = [
                    'path' => $relPath,
                    'post_media_id' => null,
                    'post_id' => null,
                    'post_status' => 'orphan_test_asset',
                    'workspace_id' => explode('/', $relPath)[1] ?? null,
                    'size_bytes' => strlen($bytes),
                    'sha256' => $sha256,
                    'ebin_cloud_id' => $uploaded['id'],
                    'verified' => true,
                    'local_removed' => $removed,
                    'migrated_at' => now()->toIso8601String(),
                ];

                $migratedCount++;
                $verifiedBytes += strlen($bytes);
            } catch (Throwable $e) {
                $this->error("Failed migrating orphan {$relPath}: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $manifest['completed_at'] = now()->toIso8601String();
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        // Also upload the migration manifest itself to Ebin Cloud Backups
        try {
            $client->upload(
                path: 'backups/migration-manifest-ebin-cloud.json',
                contents: (string) file_get_contents($manifestPath),
                mimeType: 'application/json',
                workspaceId: null,
                category: 'backup',
            );
        } catch (Throwable) {
            // non-fatal
        }

        $this->info("Migrated and verified {$migratedCount} file(s) ({$verifiedBytes} bytes) to Ebin Cloud.");

        return self::SUCCESS;
    }
}
