<?php

declare(strict_types=1);

namespace App\Services\Storage;

use DateTimeInterface;
use Illuminate\Support\Facades\URL;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Throwable;

/**
 * Flysystem v3 adapter backed by Ebin Cloud Storage API (/api/v1/storage/*).
 */
class EbinCloudFilesystemAdapter implements FilesystemAdapter
{
    public function __construct(private readonly EbinCloudClient $client) {}

    public function getClient(): EbinCloudClient
    {
        return $this->client;
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->client->fileExists($path);
        } catch (Throwable) {
            return false;
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            return $this->client->directoryExists($path);
        } catch (Throwable) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $mime = $config->get('mimetype');
            $this->client->upload($path, $contents, is_string($mime) ? $mime : null);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $mime = $config->get('mimetype');
            $this->client->uploadStream($path, $contents, is_string($mime) ? $mime : null);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        try {
            return $this->client->download($path);
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function readStream(string $path)
    {
        try {
            return $this->client->downloadStream($path);
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->client->delete($path);
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        // Ebin Cloud folders are virtual and reference-guarded; never bulk-delete directories automatically.
    }

    public function createDirectory(string $path, Config $config): void
    {
        if (! $this->client->isConfigured()) {
            throw UnableToCreateDirectory::atLocation($path, 'Ebin Cloud is not configured.');
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if (! in_array($visibility, [Visibility::PRIVATE, Visibility::PUBLIC], true)) {
            throw UnableToSetVisibility::atLocation($path, 'Unsupported visibility.');
        }
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        $meta = $this->requireMetadata($path, 'mime_type');

        return new FileAttributes(
            $path,
            $meta['size'],
            Visibility::PRIVATE,
            $meta['modifiedTime'],
            $meta['mimeType'],
            ['ebin_cloud_id' => $meta['id'], 'sha256' => $meta['sha256']],
        );
    }

    public function lastModified(string $path): FileAttributes
    {
        $meta = $this->requireMetadata($path, 'last_modified');

        return new FileAttributes(
            $path,
            $meta['size'],
            Visibility::PRIVATE,
            $meta['modifiedTime'],
            $meta['mimeType'],
            ['ebin_cloud_id' => $meta['id'], 'sha256' => $meta['sha256']],
        );
    }

    public function fileSize(string $path): FileAttributes
    {
        $meta = $this->requireMetadata($path, 'file_size');

        return new FileAttributes(
            $path,
            $meta['size'],
            Visibility::PRIVATE,
            $meta['modifiedTime'],
            $meta['mimeType'],
            ['ebin_cloud_id' => $meta['id'], 'sha256' => $meta['sha256']],
        );
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = trim($path, '/');
        $files = $this->client->listFiles(prefix: $prefix !== '' ? $prefix.'/' : null);
        $seenDirs = [];

        foreach ($files as $file) {
            $logical = $file['logicalPath'];
            if (! $deep && $prefix !== '') {
                $rel = ltrim(substr($logical, strlen($prefix)), '/');
                if (str_contains($rel, '/')) {
                    $dirSegment = explode('/', $rel)[0];
                    $dirPath = $prefix.'/'.$dirSegment;
                    if (! isset($seenDirs[$dirPath])) {
                        $seenDirs[$dirPath] = true;
                        yield new DirectoryAttributes($dirPath, Visibility::PRIVATE, $file['modifiedTime']);
                    }

                    continue;
                }
            }

            yield new FileAttributes(
                $logical,
                $file['size'],
                Visibility::PRIVATE,
                $file['modifiedTime'],
                $file['mimeType'],
                ['ebin_cloud_id' => $file['id'], 'sha256' => $file['sha256']],
            );
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->move($source, $destination);
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->copy($source, $destination);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function getUrl(string $path): string
    {
        return $this->getTemporaryUrl($path, now()->addMinutes((int) config('media.signed_url_ttl_minutes', 60)));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function getTemporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        try {
            return URL::temporarySignedRoute('media.signed', $expiration, ['path' => ltrim($path, '/')]);
        } catch (Throwable) {
            return '/private/'.ltrim($path, '/');
        }
    }

    /**
     * @return array{id: string, name: string, logicalPath: string, mimeType: string, size: int, sha256: string|null, driveAccount: string, driveFileId: string, modifiedTime: int, archived: bool}
     */
    private function requireMetadata(string $path, string $type): array
    {
        try {
            $meta = $this->client->metadata($path);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::create($path, $type, $e->getMessage(), $e);
        }

        if ($meta === null) {
            throw UnableToRetrieveMetadata::create($path, $type, 'File not found in Ebin Cloud.');
        }

        return $meta;
    }
}
