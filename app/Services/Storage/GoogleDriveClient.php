<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Drive REST API v3 client for SocialMesh media storage.
 *
 * Uses minimum scope `https://www.googleapis.com/auth/drive.file`, organizes
 * files into folder hierarchies by path (`media/{workspace}/{campaign}/...`),
 * retries transient 429/5xx errors, refreshes expired tokens automatically,
 * and never deletes original Drive files unless explicitly configured.
 *
 * @phpstan-type DriveFileMeta array{id: string, name: string, mimeType: string, size: int, md5Checksum: string|null, modifiedTime: int, path: string}
 */
class GoogleDriveClient
{
    private const string API_BASE = 'https://www.googleapis.com/drive/v3';

    private const string UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';

    private const string FOLDER_MIME = 'application/vnd.google-apps.folder';

    private const string FILE_FIELDS = 'id,name,mimeType,size,md5Checksum,modifiedTime,appProperties';

    /** @var array<string, string> */
    private array $folderCache = [];

    /** @var array<string, DriveFileMeta> */
    private array $fileCache = [];

    public function __construct(
        private readonly GoogleDriveTokenProvider $tokens,
        private readonly string $rootFolderId = 'root',
        private readonly bool $allowDelete = false,
        private readonly string $rootFolderName = 'SocialMesh',
    ) {}

    /**
     * Probe the Google Drive connection, account quota/user info (`about`), and ensure the root folder exists.
     *
     * @return array{healthy: bool, email: string|null, root_folder_id: string, quota_used_bytes: int|null, quota_limit_bytes: int|null}
     */
    public function verifyConnection(): array
    {
        $aboutUrl = self::API_BASE.'/about?fields='.rawurlencode('user(emailAddress,displayName),storageQuota(limit,usage,usageInDrive)');
        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->get($aboutUrl));

        $email = $response->json('user.emailAddress');
        $usage = $response->json('storageQuota.usage');
        $limit = $response->json('storageQuota.limit');

        $rootId = $this->effectiveRootFolderId();

        GoogleDriveTokenProvider::recordHealthCheck(
            healthy: true,
            error: null,
            email: is_string($email) && $email !== '' ? $email : null,
            rootFolderId: $rootId !== 'root' ? $rootId : null,
            quotaUsedBytes: is_numeric($usage) ? (int) $usage : null,
            quotaLimitBytes: is_numeric($limit) ? (int) $limit : null,
        );

        return [
            'healthy' => true,
            'email' => is_string($email) && $email !== '' ? $email : null,
            'root_folder_id' => $rootId,
            'quota_used_bytes' => is_numeric($usage) ? (int) $usage : null,
            'quota_limit_bytes' => is_numeric($limit) ? (int) $limit : null,
        ];
    }

    /**
     * Upload raw bytes to Google Drive at a logical relative path (e.g. `media/ws-1/launch/photo.jpg`).
     *
     * @param  array<string, string>  $properties
     * @return DriveFileMeta
     */
    public function upload(string $path, string $contents, ?string $mimeType = null, array $properties = []): array
    {
        $normalized = $this->normalizePath($path);
        [$directory, $filename] = $this->splitPath($normalized);
        $parentId = $this->ensureDirectory($directory);
        $mime = $mimeType !== null && $mimeType !== '' ? $mimeType : ($this->guessMime($filename, $contents) ?? 'application/octet-stream');

        $existing = $this->findFileInParent($parentId, $filename, $normalized);

        $metadata = [
            'name' => $filename,
            'mimeType' => $mime,
            'appProperties' => array_merge([
                'socialmesh_path' => $normalized,
                'sha256' => hash('sha256', $contents),
            ], $properties),
        ];

        if ($existing === null) {
            $metadata['parents'] = [$parentId];
        }

        $boundary = 'socialmesh_drive_'.bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n"
            .json_encode($metadata, JSON_THROW_ON_ERROR)."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: {$mime}\r\n\r\n"
            .$contents."\r\n"
            ."--{$boundary}--";

        $url = $existing !== null
            ? self::UPLOAD_BASE.'/files/'.rawurlencode($existing['id']).'?uploadType=multipart&fields='.rawurlencode(self::FILE_FIELDS)
            : self::UPLOAD_BASE.'/files?uploadType=multipart&fields='.rawurlencode(self::FILE_FIELDS);

        $method = $existing !== null ? 'PATCH' : 'POST';

        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->send($method, $url));

        $meta = $this->parseFileMeta($response->json(), $normalized, strlen($contents), $mime);
        $this->fileCache[$normalized] = $meta;

        return $meta;
    }

    /**
     * Upload a stream resource to Google Drive, reading in bounded memory and cleaning up any temp buffer.
     *
     * @param  resource  $stream
     * @param  array<string, string>  $properties
     * @return DriveFileMeta
     */
    public function uploadStream(string $path, $stream, ?string $mimeType = null, array $properties = []): array
    {
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw GoogleDriveStorageException::transferFailed('Could not read source stream for Google Drive upload.');
        }

        return $this->upload($path, $contents, $mimeType, $properties);
    }

    /**
     * Download file contents by logical path or direct Drive file ID (`id:...`).
     */
    public function download(string $pathOrId): string
    {
        $fileId = $this->resolveFileId($pathOrId);
        if ($fileId === null) {
            throw GoogleDriveStorageException::transferFailed("File not found on Google Drive: {$pathOrId}", 404);
        }

        $url = self::API_BASE.'/files/'.rawurlencode($fileId).'?alt=media';
        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->get($url));

        return $response->body();
    }

    /**
     * Download a file into a PHP temporary stream (`php://temp`) suitable for Flysystem `readStream`.
     *
     * @return resource
     */
    public function downloadStream(string $pathOrId)
    {
        $bytes = $this->download($pathOrId);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw GoogleDriveStorageException::transferFailed('Could not allocate temporary stream for Google Drive download.');
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    /**
     * Look up file metadata by logical path or `id:{driveFileId}`.
     *
     * @return DriveFileMeta|null
     */
    public function metadata(string $pathOrId): ?array
    {
        if (str_starts_with($pathOrId, 'id:')) {
            $fileId = substr($pathOrId, 3);

            return $this->metadataById($fileId, $pathOrId);
        }

        $normalized = $this->normalizePath($pathOrId);
        if (isset($this->fileCache[$normalized])) {
            return $this->fileCache[$normalized];
        }

        [$directory, $filename] = $this->splitPath($normalized);
        if ($filename === '') {
            return null;
        }

        $parentId = $this->findDirectoryId($directory);
        if ($parentId === null) {
            return null;
        }

        return $this->findFileInParent($parentId, $filename, $normalized);
    }

    /**
     * Fetch file metadata directly by Google Drive file ID.
     *
     * @return DriveFileMeta|null
     */
    public function metadataById(string $fileId, string $logicalPath = ''): ?array
    {
        $url = self::API_BASE.'/files/'.rawurlencode($fileId).'?fields='.rawurlencode(self::FILE_FIELDS.',trashed');

        try {
            $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->get($url));
        } catch (GoogleDriveStorageException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        $json = $response->json();
        if (! is_array($json) || ! empty($json['trashed'])) {
            return null;
        }

        return $this->parseFileMeta($json, $logicalPath !== '' ? $logicalPath : ($json['name'] ?? $fileId));
    }

    public function fileExists(string $path): bool
    {
        return $this->metadata($path) !== null;
    }

    public function directoryExists(string $path): bool
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === '') {
            return true;
        }

        return $this->findDirectoryId($normalized) !== null;
    }

    /**
     * Ensure the nested folder chain exists under the configured root folder and return the leaf folder ID.
     */
    public function ensureDirectory(string $directory): string
    {
        $normalized = $this->normalizePath($directory);
        if ($normalized === '') {
            return $this->effectiveRootFolderId();
        }

        if (isset($this->folderCache[$normalized])) {
            return $this->folderCache[$normalized];
        }

        $parentId = $this->effectiveRootFolderId();
        $accumulated = '';

        foreach (explode('/', $normalized) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $accumulated = $accumulated === '' ? $segment : "{$accumulated}/{$segment}";
            if (isset($this->folderCache[$accumulated])) {
                $parentId = $this->folderCache[$accumulated];

                continue;
            }

            $existingId = $this->findChildFolder($parentId, $segment);
            if ($existingId !== null) {
                $this->folderCache[$accumulated] = $existingId;
                $parentId = $existingId;

                continue;
            }

            $createdId = $this->createChildFolder($parentId, $segment);
            $this->folderCache[$accumulated] = $createdId;
            $parentId = $createdId;
        }

        return $parentId;
    }

    /**
     * Original Google Drive files are never deleted automatically unless allowDelete is explicitly true.
     */
    public function delete(string $path): void
    {
        $normalized = $this->normalizePath($path);
        unset($this->fileCache[$normalized]);

        if (! $this->allowDelete) {
            return;
        }

        $meta = $this->metadata($normalized);
        if ($meta === null) {
            return;
        }

        $url = self::API_BASE.'/files/'.rawurlencode($meta['id']);
        $this->sendWithRetry(fn (PendingRequest $http): Response => $http->delete($url));
    }

    /**
     * Copy a file within Google Drive.
     */
    public function copy(string $source, string $destination): void
    {
        $bytes = $this->download($source);
        $sourceMeta = $this->metadata($source);
        $this->upload($destination, $bytes, $sourceMeta['mimeType'] ?? null);
    }

    /**
     * Move a file within Google Drive (copies to destination; preserves original when allowDelete is false).
     */
    public function move(string $source, string $destination): void
    {
        $this->copy($source, $destination);
        $this->delete($source);
    }

    /**
     * List files and folders directly inside a logical directory.
     *
     * @return list<array{type: 'file'|'dir', path: string, id: string, mimeType: string, size: int, modifiedTime: int}>
     */
    public function listDirectory(string $directory = ''): array
    {
        $normalized = $this->normalizePath($directory);
        $folderId = $normalized === '' ? $this->effectiveRootFolderId() : $this->findDirectoryId($normalized);
        if ($folderId === null) {
            return [];
        }

        $escapedParent = str_replace(['\\', "'"], ['\\\\', "\\'"], $folderId);
        $query = "'{$escapedParent}' in parents and trashed = false";
        $url = self::API_BASE.'/files?q='.rawurlencode($query).'&fields='.rawurlencode('files('.self::FILE_FIELDS.')').'&pageSize=100';

        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->get($url));
        $files = $response->json('files', []);
        if (! is_array($files)) {
            return [];
        }

        $items = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! isset($file['id'], $file['name'])) {
                continue;
            }
            $childPath = $normalized === '' ? (string) $file['name'] : "{$normalized}/{$file['name']}";
            $isDir = ($file['mimeType'] ?? '') === self::FOLDER_MIME;
            $items[] = [
                'type' => $isDir ? 'dir' : 'file',
                'path' => $childPath,
                'id' => (string) $file['id'],
                'mimeType' => (string) ($file['mimeType'] ?? 'application/octet-stream'),
                'size' => (int) ($file['size'] ?? 0),
                'modifiedTime' => isset($file['modifiedTime']) ? (int) (strtotime((string) $file['modifiedTime']) ?: time()) : time(),
            ];
        }

        return $items;
    }

    public function resolveFileId(string $pathOrId): ?string
    {
        if (str_starts_with($pathOrId, 'id:')) {
            return substr($pathOrId, 3);
        }

        $meta = $this->metadata($pathOrId);

        return $meta['id'] ?? null;
    }

    private function effectiveRootFolderId(): string
    {
        $status = $this->tokens->status();
        $id = trim($status['root_folder_id'] !== '' ? $status['root_folder_id'] : $this->rootFolderId);

        if ($id !== '' && $id !== 'root') {
            return $id;
        }

        $folderName = trim($this->rootFolderName);
        if ($folderName === '' || strtolower($folderName) === 'root') {
            return 'root';
        }

        if (isset($this->folderCache['__root__:'.$folderName])) {
            return $this->folderCache['__root__:'.$folderName];
        }

        $existingId = $this->findChildFolder('root', $folderName);
        if ($existingId !== null) {
            $this->folderCache['__root__:'.$folderName] = $existingId;

            return $existingId;
        }

        $createdId = $this->createChildFolder('root', $folderName);
        $this->folderCache['__root__:'.$folderName] = $createdId;

        return $createdId;
    }

    private function findDirectoryId(string $directory): ?string
    {
        $normalized = $this->normalizePath($directory);
        if ($normalized === '') {
            return $this->effectiveRootFolderId();
        }

        if (isset($this->folderCache[$normalized])) {
            return $this->folderCache[$normalized];
        }

        $parentId = $this->effectiveRootFolderId();
        $accumulated = '';

        foreach (explode('/', $normalized) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $accumulated = $accumulated === '' ? $segment : "{$accumulated}/{$segment}";
            if (isset($this->folderCache[$accumulated])) {
                $parentId = $this->folderCache[$accumulated];

                continue;
            }

            $found = $this->findChildFolder($parentId, $segment);
            if ($found === null) {
                return null;
            }

            $this->folderCache[$accumulated] = $found;
            $parentId = $found;
        }

        return $parentId;
    }

    private function findChildFolder(string $parentId, string $name): ?string
    {
        $escapedParent = str_replace(['\\', "'"], ['\\\\', "\\'"], $parentId);
        $escapedName = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
        $query = "'{$escapedParent}' in parents and name = '{$escapedName}' and mimeType = '".self::FOLDER_MIME."' and trashed = false";
        $url = self::API_BASE.'/files?q='.rawurlencode($query).'&fields='.rawurlencode('files(id,name)').'&pageSize=1';

        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->get($url));
        $files = $response->json('files', []);

        if (is_array($files) && isset($files[0]['id']) && is_string($files[0]['id'])) {
            return $files[0]['id'];
        }

        return null;
    }

    private function createChildFolder(string $parentId, string $name): string
    {
        $url = self::API_BASE.'/files?fields=id,name';
        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->post($url, [
            'name' => $name,
            'mimeType' => self::FOLDER_MIME,
            'parents' => [$parentId],
        ]));

        $id = trim((string) $response->json('id', ''));
        if ($id === '') {
            throw GoogleDriveStorageException::transferFailed("Could not create Google Drive folder '{$name}'.");
        }

        return $id;
    }

    /**
     * @return DriveFileMeta|null
     */
    private function findFileInParent(string $parentId, string $filename, string $logicalPath): ?array
    {
        $escapedParent = str_replace(['\\', "'"], ['\\\\', "\\'"], $parentId);
        $escapedName = str_replace(['\\', "'"], ['\\\\', "\\'"], $filename);
        $query = "'{$escapedParent}' in parents and name = '{$escapedName}' and mimeType != '".self::FOLDER_MIME."' and trashed = false";
        $url = self::API_BASE.'/files?q='.rawurlencode($query).'&fields='.rawurlencode('files('.self::FILE_FIELDS.')').'&pageSize=1';

        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->get($url));
        $files = $response->json('files', []);

        if (is_array($files) && isset($files[0]) && is_array($files[0])) {
            $meta = $this->parseFileMeta($files[0], $logicalPath);
            $this->fileCache[$logicalPath] = $meta;

            return $meta;
        }

        return null;
    }

    /**
     * Execute a Google Drive request with automatic token refresh on 401 and bounded retry on 429/5xx.
     *
     * @param  callable(PendingRequest): Response  $operation
     */
    private function sendWithRetry(callable $operation): Response
    {
        $maxAttempts = 3;
        $refreshedToken = false;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $token = $this->tokens->accessToken($refreshedToken);
            $refreshedToken = false;

            try {
                $response = $operation(
                    Http::withToken($token)
                        ->acceptJson()
                        ->timeout(30)
                );
            } catch (ConnectionException $e) {
                if ($attempt < $maxAttempts) {
                    usleep($attempt * 150_000);

                    continue;
                }

                throw GoogleDriveStorageException::transferFailed('Network error while communicating with Google Drive.', 503, $e);
            } catch (Throwable $e) {
                if ($e instanceof GoogleDriveStorageException) {
                    throw $e;
                }

                throw GoogleDriveStorageException::transferFailed($e->getMessage(), 500, $e);
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $reason = $this->extractErrorReason($response);

            if ($status === 401 && $attempt < $maxAttempts) {
                $this->tokens->clearCachedToken();
                $refreshedToken = true;

                continue;
            }

            if ($status === 401) {
                throw GoogleDriveStorageException::expiredOrRevokedToken($reason);
            }

            if ($status === 403 && in_array($reason, ['storageQuotaExceeded', 'teamDriveFileLimitExceeded', 'numChildrenInNonRootLimitExceeded'], true)) {
                throw GoogleDriveStorageException::quotaExceeded();
            }

            if (($status === 429 || ($status === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) || $status >= 500) && $attempt < $maxAttempts) {
                usleep($attempt * 200_000);

                continue;
            }

            if ($status === 429 || ($status === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true))) {
                throw GoogleDriveStorageException::rateLimited();
            }

            $message = (string) ($response->json('error.message') ?? "HTTP {$status}");

            throw GoogleDriveStorageException::transferFailed($message, $status);
        }

        throw GoogleDriveStorageException::transferFailed('Maximum retry attempts exceeded.');
    }

    private function extractErrorReason(Response $response): string
    {
        $errors = $response->json('error.errors');
        if (is_array($errors) && isset($errors[0]['reason']) && is_string($errors[0]['reason'])) {
            return $errors[0]['reason'];
        }

        return (string) ($response->json('error.status') ?? '');
    }

    /**
     * @return DriveFileMeta
     */
    private function parseFileMeta(mixed $raw, string $logicalPath, int $fallbackSize = 0, string $fallbackMime = 'application/octet-stream'): array
    {
        $data = is_array($raw) ? $raw : [];
        $id = trim((string) ($data['id'] ?? ''));
        if ($id === '') {
            throw GoogleDriveStorageException::transferFailed('Google Drive response did not contain a file ID.');
        }

        $modified = isset($data['modifiedTime']) && is_string($data['modifiedTime'])
            ? (int) (strtotime($data['modifiedTime']) ?: time())
            : time();

        return [
            'id' => $id,
            'name' => (string) ($data['name'] ?? basename($logicalPath)),
            'mimeType' => (string) ($data['mimeType'] ?? $fallbackMime),
            'size' => isset($data['size']) ? (int) $data['size'] : $fallbackSize,
            'md5Checksum' => isset($data['md5Checksum']) && is_string($data['md5Checksum']) ? $data['md5Checksum'] : null,
            'modifiedTime' => $modified,
            'path' => $logicalPath,
        ];
    }

    private function normalizePath(string $path): string
    {
        $clean = str_replace('\\', '/', trim($path));
        $parts = [];

        foreach (explode('/', $clean) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPath(string $normalizedPath): array
    {
        $pos = strrpos($normalizedPath, '/');
        if ($pos === false) {
            return ['', $normalizedPath];
        }

        return [substr($normalizedPath, 0, $pos), substr($normalizedPath, $pos + 1)];
    }

    private function guessMime(string $filename, string $contents): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            default => (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: null,
        };
    }
}
