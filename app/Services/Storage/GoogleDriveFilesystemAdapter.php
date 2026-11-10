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
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Throwable;

/**
 * Flysystem v3 adapter backed by Google Drive API v3.
 *
 * Original Google Drive files are never deleted automatically (`delete` and
 * `deleteDirectory` are safe no-ops unless `allow_delete` is explicitly enabled).
 */
class GoogleDriveFilesystemAdapter implements FilesystemAdapter
{
    public function __construct(private readonly GoogleDriveClient $client) {}

    public function getClient(): GoogleDriveClient
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
        // Original Google Drive files are never deleted automatically.
        $this->client->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        // Original Google Drive directories/files are never deleted automatically.
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->client->ensureDirectory($path);
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
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
            ['drive_file_id' => $meta['id'], 'md5' => $meta['md5Checksum']],
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
            ['drive_file_id' => $meta['id'], 'md5' => $meta['md5Checksum']],
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
            ['drive_file_id' => $meta['id'], 'md5' => $meta['md5Checksum']],
        );
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $items = $this->client->listDirectory($path);

        foreach ($items as $item) {
            if ($item['type'] === 'dir') {
                yield new DirectoryAttributes($item['path'], Visibility::PRIVATE, $item['modifiedTime']);
                if ($deep) {
                    yield from $this->listContents($item['path'], true);
                }
            } else {
                yield new FileAttributes(
                    $item['path'],
                    $item['size'],
                    Visibility::PRIVATE,
                    $item['modifiedTime'],
                    $item['mimeType'],
                    ['drive_file_id' => $item['id']],
                );
            }
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

    /**
     * Called by Laravel's FilesystemAdapter::url($path).
     */
    public function getUrl(string $path): string
    {
        return $this->getTemporaryUrl($path, now()->addMinutes((int) config('media.signed_url_ttl_minutes', 60)));
    }

    /**
     * Called by Laravel's FilesystemAdapter::temporaryUrl($path, $expiration, $options).
     *
     * @param  array<string, mixed>  $options
     */
    public function getTemporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        try {
            return URL::temporarySignedRoute('storage.local', $expiration, ['path' => ltrim($path, '/')]);
        } catch (Throwable) {
            return '/private/'.ltrim($path, '/');
        }
    }

    /**
     * @return array{id: string, name: string, mimeType: string, size: int, md5Checksum: string|null, modifiedTime: int, path: string}
     */
    private function requireMetadata(string $path, string $type): array
    {
        try {
            $meta = $this->client->metadata($path);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::create($path, $type, $e->getMessage(), $e);
        }

        if ($meta === null) {
            throw UnableToRetrieveMetadata::create($path, $type, 'File not found on Google Drive.');
        }

        return $meta;
    }
}
