<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Service-to-service client for Ebin Cloud Storage API (/api/v1/storage/*).
 *
 * Authenticates via Bearer service key (`EBIN_CLOUD_SERVICE_KEY`), enforces
 * SHA-256 checksum verification, supports idempotent uploads, bounded local
 * read-through disk cache, and never exposes secrets in logs.
 *
 * @phpstan-type EbinCloudFileMeta array{id: string, name: string, logicalPath: string, mimeType: string, size: int, sha256: string|null, driveAccount: string, driveFileId: string, modifiedTime: int, archived: bool}
 */
class EbinCloudClient
{
    /** @var array<string, EbinCloudFileMeta> */
    private array $metaCache = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $serviceKey,
        private readonly string $appId = 'socialmesh',
        private readonly bool $allowDelete = false,
        private readonly ?string $localCacheDir = null,
        private readonly int $localCacheMaxBytes = 268_435_456,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->baseUrl) !== '' && trim($this->serviceKey) !== '';
    }

    /**
     * Probe Ebin Cloud storage health, namespace statistics, and underlying Google Drive account quotas.
     *
     * @return array{healthy: bool, namespace: string, file_count: int, total_bytes: int, quota_used_bytes: int, quota_limit_bytes: int, accounts: list<array<string, mixed>>}
     */
    public function verifyConnection(): array
    {
        $this->ensureConfigured();

        $response = $this->sendWithRetry(
            fn (PendingRequest $http): Response => $http->get($this->endpoint('/api/v1/storage/status'))
        );

        $json = $response->json();
        $rawAccounts = is_array($json['accounts'] ?? null) ? $json['accounts'] : [];
        /** @var list<array<string, mixed>> $accounts */
        $accounts = [];
        $totalLimit = 0;
        $totalUsed = 0;
        foreach ($rawAccounts as $acc) {
            if (is_array($acc)) {
                /** @var array<string, mixed> $acc */
                $accounts[] = $acc;
                $totalLimit += (int) ($acc['limit'] ?? 0);
                $totalUsed += (int) ($acc['usage'] ?? 0);
            }
        }

        $result = [
            'healthy' => true,
            'namespace' => (string) ($json['namespace'] ?? 'Applications/SocialMesh'),
            'file_count' => (int) ($json['file_count'] ?? 0),
            'total_bytes' => (int) ($json['total_bytes'] ?? 0),
            'quota_used_bytes' => $totalUsed,
            'quota_limit_bytes' => $totalLimit,
            'accounts' => $accounts,
        ];

        self::recordHealthCheck(
            healthy: true,
            error: null,
            quotaUsedBytes: $totalUsed,
            quotaLimitBytes: $totalLimit,
            fileCount: $result['file_count'],
        );

        return $result;
    }

    /**
     * Upload raw bytes to Ebin Cloud at a logical path with SHA-256 verification and idempotency protection.
     *
     * @param  array<string, mixed>  $metadata
     * @return EbinCloudFileMeta
     */
    public function upload(
        string $path,
        string $contents,
        ?string $mimeType = null,
        ?string $workspaceId = null,
        string $category = 'media',
        ?string $idempotencyKey = null,
        array $metadata = [],
    ): array {
        $this->ensureConfigured();

        $normalized = $this->normalizePath($path);
        $filename = basename($normalized) ?: 'file.bin';
        $sha256 = hash('sha256', $contents);
        $mime = $mimeType !== null && $mimeType !== '' ? $mimeType : ($this->guessMime($filename, $contents) ?? 'application/octet-stream');
        $resolvedWorkspace = $workspaceId ?? $this->inferWorkspaceId($normalized);
        $resolvedCategory = $category !== 'media' ? $category : $this->inferCategory($normalized);
        $idem = $idempotencyKey ?? hash('sha256', $normalized.':'.$sha256);

        $headers = [
            'X-File-Name' => $filename,
            'X-Mime-Type' => $mime,
            'X-Logical-Path' => $normalized,
            'X-Category' => $resolvedCategory,
            'X-Sha256' => $sha256,
            'X-Idempotency-Key' => $idem,
        ];

        if ($resolvedWorkspace !== null && $resolvedWorkspace !== '') {
            $headers['X-Workspace-Id'] = $resolvedWorkspace;
        }
        if ($metadata !== []) {
            $headers['X-Metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);
        }

        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http
            ->timeout(180)
            ->withHeaders($headers)
            ->withBody($contents, $mime)
            ->post($this->endpoint('/api/v1/storage/upload')));

        $file = $response->json('file');
        if (! is_array($file)) {
            throw GoogleDriveStorageException::transferFailed('Ebin Cloud upload did not return file metadata.');
        }

        $parsed = $this->parseFileMeta($file, $normalized);
        if ($parsed['sha256'] !== null && strtolower($parsed['sha256']) !== strtolower($sha256)) {
            throw GoogleDriveStorageException::transferFailed('Ebin Cloud SHA-256 verification failed after upload.');
        }

        $this->metaCache[$normalized] = $parsed;
        $this->metaCache['id:'.$parsed['id']] = $parsed;
        $this->writeLocalCache($parsed['id'], $contents);

        return $parsed;
    }

    /**
     * Upload a stream resource to Ebin Cloud.
     *
     * @param  resource  $stream
     * @param  array<string, mixed>  $metadata
     * @return EbinCloudFileMeta
     */
    public function uploadStream(
        string $path,
        $stream,
        ?string $mimeType = null,
        ?string $workspaceId = null,
        string $category = 'media',
        ?string $idempotencyKey = null,
        array $metadata = [],
    ): array {
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw GoogleDriveStorageException::transferFailed('Could not read source stream for Ebin Cloud upload.');
        }

        return $this->upload($path, $contents, $mimeType, $workspaceId, $category, $idempotencyKey, $metadata);
    }

    /**
     * Download file contents by logical path or `id:{storageId}`, using the bounded local read-through cache when warm.
     */
    public function download(string $pathOrId): string
    {
        $this->ensureConfigured();

        $meta = $this->metadata($pathOrId);
        if ($meta === null) {
            throw GoogleDriveStorageException::transferFailed("File not found in Ebin Cloud: {$pathOrId}", 404);
        }

        $cached = $this->readLocalCache($meta['id'], $meta['sha256']);
        if ($cached !== null) {
            return $cached;
        }

        $url = $this->endpoint('/api/v1/storage/files/'.rawurlencode($meta['id']).'/download');
        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->timeout(180)->get($url));
        $bytes = $response->body();

        if ($meta['sha256'] !== null && $meta['sha256'] !== '' && hash('sha256', $bytes) !== strtolower($meta['sha256'])) {
            throw GoogleDriveStorageException::transferFailed("SHA-256 integrity check failed when downloading {$pathOrId} from Ebin Cloud.");
        }

        $this->writeLocalCache($meta['id'], $bytes);

        return $bytes;
    }

    /**
     * Download a file into a PHP temporary stream (`php://temp`) suitable for Flysystem `readStream`.
     *
     * @return resource
     */
    public function downloadStream(string $pathOrId)
    {
        $bytes = $this->download($pathOrId);
        $stream = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b');
        if ($stream === false) {
            throw GoogleDriveStorageException::transferFailed('Could not allocate temporary stream for Ebin Cloud download.');
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    /**
     * Look up file metadata by logical path or `id:{storageId}`.
     *
     * @return EbinCloudFileMeta|null
     */
    public function metadata(string $pathOrId, bool $verifyRemote = false): ?array
    {
        $this->ensureConfigured();

        if (str_starts_with($pathOrId, 'id:')) {
            $id = substr($pathOrId, 3);

            return $this->metadataById($id, $verifyRemote);
        }

        $normalized = $this->normalizePath($pathOrId);
        if ($normalized === '') {
            return null;
        }

        if (! $verifyRemote && isset($this->metaCache[$normalized])) {
            return $this->metaCache[$normalized];
        }

        $query = http_build_query([
            'path' => $normalized,
            'verify' => $verifyRemote ? '1' : '0',
        ]);

        try {
            $response = $this->sendWithRetry(
                fn (PendingRequest $http): Response => $http->get($this->endpoint('/api/v1/storage/by-path?'.$query))
            );
        } catch (GoogleDriveStorageException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        $file = $response->json('file');
        if (! is_array($file)) {
            return null;
        }

        $parsed = $this->parseFileMeta($file, $normalized);
        $this->metaCache[$normalized] = $parsed;
        $this->metaCache['id:'.$parsed['id']] = $parsed;

        return $parsed;
    }

    /**
     * Look up file metadata directly by Ebin Cloud storage UUID.
     *
     * @return EbinCloudFileMeta|null
     */
    public function metadataById(string $id, bool $verifyRemote = false): ?array
    {
        $this->ensureConfigured();

        $id = trim($id);
        if ($id === '') {
            return null;
        }

        if (! $verifyRemote && isset($this->metaCache['id:'.$id])) {
            return $this->metaCache['id:'.$id];
        }

        $url = $this->endpoint('/api/v1/storage/files/'.rawurlencode($id).($verifyRemote ? '?verify=1' : ''));

        try {
            $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->get($url));
        } catch (GoogleDriveStorageException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        $file = $response->json('file');
        if (! is_array($file)) {
            return null;
        }

        $parsed = $this->parseFileMeta($file, (string) ($file['logical_path'] ?? $id));
        $this->metaCache[$parsed['logicalPath']] = $parsed;
        $this->metaCache['id:'.$parsed['id']] = $parsed;

        return $parsed;
    }

    public function fileExists(string $pathOrId, bool $verifyRemote = false): bool
    {
        return $this->metadata($pathOrId, $verifyRemote) !== null;
    }

    public function directoryExists(string $path): bool
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === '') {
            return true;
        }

        return $this->listFiles(prefix: $normalized.'/') !== [];
    }

    /**
     * List files in the SocialMesh namespace on Ebin Cloud.
     *
     * @return list<EbinCloudFileMeta>
     */
    public function listFiles(?string $workspaceId = null, ?string $prefix = null, ?string $category = null): array
    {
        $this->ensureConfigured();

        $params = array_filter([
            'workspace_id' => $workspaceId,
            'prefix' => $prefix !== null ? $this->normalizePath($prefix) : null,
            'category' => $category,
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        $url = $this->endpoint('/api/v1/storage/files'.($params !== [] ? '?'.http_build_query($params) : ''));
        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->get($url));
        $files = $response->json('files', []);

        if (! is_array($files)) {
            return [];
        }

        $result = [];
        foreach ($files as $file) {
            if (is_array($file) && isset($file['id'])) {
                $parsed = $this->parseFileMeta($file, (string) ($file['logical_path'] ?? $file['name'] ?? $file['id']));
                $this->metaCache[$parsed['logicalPath']] = $parsed;
                $this->metaCache['id:'.$parsed['id']] = $parsed;
                $result[] = $parsed;
            }
        }

        return $result;
    }

    /**
     * Copy a file within Ebin Cloud.
     *
     * @return EbinCloudFileMeta
     */
    public function copy(string $sourcePathOrId, string $destinationPath): array
    {
        $this->ensureConfigured();

        $sourceMeta = $this->metadata($sourcePathOrId);
        if ($sourceMeta === null) {
            throw GoogleDriveStorageException::transferFailed("Source file not found in Ebin Cloud: {$sourcePathOrId}", 404);
        }

        $normalizedDest = $this->normalizePath($destinationPath);
        $url = $this->endpoint('/api/v1/storage/files/'.rawurlencode($sourceMeta['id']).'/copy');
        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->post($url, [
            'logical_path' => $normalizedDest,
            'workspace_id' => $this->inferWorkspaceId($normalizedDest),
            'category' => $this->inferCategory($normalizedDest),
        ]));

        $file = $response->json('file');
        if (! is_array($file)) {
            throw GoogleDriveStorageException::transferFailed('Ebin Cloud copy did not return file metadata.');
        }

        $parsed = $this->parseFileMeta($file, $normalizedDest);
        $this->metaCache[$normalizedDest] = $parsed;
        $this->metaCache['id:'.$parsed['id']] = $parsed;

        return $parsed;
    }

    /**
     * Move/rename a file's logical path within Ebin Cloud.
     *
     * @return EbinCloudFileMeta
     */
    public function move(string $sourcePathOrId, string $destinationPath): array
    {
        $this->ensureConfigured();

        $sourceMeta = $this->metadata($sourcePathOrId);
        if ($sourceMeta === null) {
            throw GoogleDriveStorageException::transferFailed("Source file not found in Ebin Cloud: {$sourcePathOrId}", 404);
        }

        $normalizedDest = $this->normalizePath($destinationPath);
        $url = $this->endpoint('/api/v1/storage/files/'.rawurlencode($sourceMeta['id']));
        $response = $this->sendWithRetry(fn (PendingRequest $http): Response => $http->patch($url, [
            'logical_path' => $normalizedDest,
        ]));

        unset($this->metaCache[$sourceMeta['logicalPath']]);
        $file = $response->json('file');
        if (! is_array($file)) {
            throw GoogleDriveStorageException::transferFailed('Ebin Cloud move did not return file metadata.');
        }

        $parsed = $this->parseFileMeta($file, $normalizedDest);
        $this->metaCache[$normalizedDest] = $parsed;
        $this->metaCache['id:'.$parsed['id']] = $parsed;

        return $parsed;
    }

    /**
     * Delete or soft-trash a file in Ebin Cloud when allowDelete is enabled (or for temporary `tmp/` paths).
     */
    public function delete(string $pathOrId, bool $forceSoftDelete = false): void
    {
        $normalized = str_starts_with($pathOrId, 'id:') ? $pathOrId : $this->normalizePath($pathOrId);
        $isTempPath = str_starts_with($normalized, 'tmp/');

        if (! $this->allowDelete && ! $forceSoftDelete && ! $isTempPath) {
            unset($this->metaCache[$normalized]);

            return;
        }

        $meta = $this->metadata($pathOrId);
        unset($this->metaCache[$normalized]);
        if ($meta === null) {
            return;
        }

        unset($this->metaCache['id:'.$meta['id']]);
        $this->evictLocalCacheFile($meta['id']);

        $permanent = $isTempPath && $this->allowDelete ? '?permanent=1' : '';
        $url = $this->endpoint('/api/v1/storage/files/'.rawurlencode($meta['id']).$permanent);
        $this->sendWithRetry(fn (PendingRequest $http): Response => $http->delete($url));
    }

    /**
     * Mark or unmark a file as archived in Ebin Cloud.
     */
    public function setArchived(string $pathOrId, bool $archived): void
    {
        $meta = $this->metadata($pathOrId);
        if ($meta === null) {
            return;
        }

        $url = $this->endpoint('/api/v1/storage/files/'.rawurlencode($meta['id']));
        $this->sendWithRetry(fn (PendingRequest $http): Response => $http->patch($url, [
            'archived' => $archived ? 1 : 0,
        ]));
        unset($this->metaCache[$meta['logicalPath']], $this->metaCache['id:'.$meta['id']]);
    }

    public static function recordHealthCheck(
        bool $healthy,
        ?string $error = null,
        ?int $quotaUsedBytes = null,
        ?int $quotaLimitBytes = null,
        ?int $fileCount = null,
    ): void {
        try {
            Cache::put('ebin_cloud:health', [
                'healthy' => $healthy,
                'last_verified_at' => $healthy ? now()->toIso8601String() : null,
                'last_error' => $healthy ? null : $error,
                'quota_used_bytes' => $quotaUsedBytes,
                'quota_limit_bytes' => $quotaLimitBytes,
                'file_count' => $fileCount,
            ], now()->addHours(24));
        } catch (Throwable) {
            // ignore cache write failure
        }
    }

    /**
     * @return array{connected: bool, healthy: bool, url: string, last_verified_at: string|null, last_error: string|null, quota_used_bytes: int|null, quota_limit_bytes: int|null, file_count: int|null}
     */
    public function status(): array
    {
        $cached = [];
        try {
            $raw = Cache::get('ebin_cloud:health');
            if (is_array($raw)) {
                $cached = $raw;
            }
        } catch (Throwable) {
            $cached = [];
        }

        return [
            'connected' => $this->isConfigured(),
            'healthy' => (bool) ($cached['healthy'] ?? $this->isConfigured()),
            'url' => $this->baseUrl,
            'last_verified_at' => isset($cached['last_verified_at']) && is_string($cached['last_verified_at']) ? $cached['last_verified_at'] : null,
            'last_error' => isset($cached['last_error']) && is_string($cached['last_error']) ? $cached['last_error'] : null,
            'quota_used_bytes' => isset($cached['quota_used_bytes']) && is_numeric($cached['quota_used_bytes']) ? (int) $cached['quota_used_bytes'] : null,
            'quota_limit_bytes' => isset($cached['quota_limit_bytes']) && is_numeric($cached['quota_limit_bytes']) ? (int) $cached['quota_limit_bytes'] : null,
            'file_count' => isset($cached['file_count']) && is_numeric($cached['file_count']) ? (int) $cached['file_count'] : null,
        ];
    }

    /**
     * Execute an Ebin Cloud HTTP request with bounded exponential backoff on network/429/5xx errors.
     *
     * @param  callable(PendingRequest): Response  $operation
     */
    private function sendWithRetry(callable $operation): Response
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $operation(
                    Http::withToken($this->serviceKey)
                        ->withHeaders([
                            'X-Ebin-Cloud-App' => $this->appId,
                            'Accept' => 'application/json',
                        ])
                        ->timeout(60)
                );
            } catch (ConnectionException $e) {
                if ($attempt < $maxAttempts) {
                    usleep($attempt * 200_000);

                    continue;
                }

                self::recordHealthCheck(false, 'Network error communicating with Ebin Cloud.');

                throw GoogleDriveStorageException::transferFailed('Network error while communicating with Ebin Cloud.', 503, $e);
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
            if (($status === 429 || $status >= 500) && $attempt < $maxAttempts) {
                usleep($attempt * 250_000);

                continue;
            }

            $message = (string) ($response->json('error') ?? $response->json('message') ?? "HTTP {$status}");
            if ($status === 401 || $status === 403) {
                self::recordHealthCheck(false, $message);

                throw GoogleDriveStorageException::expiredOrRevokedToken($message);
            }
            if ($status === 429) {
                throw GoogleDriveStorageException::rateLimited();
            }

            throw GoogleDriveStorageException::transferFailed($message, $status);
        }

        throw GoogleDriveStorageException::transferFailed('Maximum Ebin Cloud retry attempts exceeded.');
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw GoogleDriveStorageException::missingCredentials();
        }
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return EbinCloudFileMeta
     */
    private function parseFileMeta(array $raw, string $fallbackLogicalPath): array
    {
        $id = trim((string) ($raw['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('Ebin Cloud response did not include a file ID.');
        }

        $logicalPath = $this->normalizePath((string) ($raw['logical_path'] ?? $fallbackLogicalPath));
        $updatedAt = isset($raw['updated_at']) && is_string($raw['updated_at'])
            ? (int) (strtotime($raw['updated_at']) ?: time())
            : time();

        return [
            'id' => $id,
            'name' => (string) ($raw['name'] ?? basename($logicalPath)),
            'logicalPath' => $logicalPath !== '' ? $logicalPath : $id,
            'mimeType' => (string) ($raw['mime_type'] ?? 'application/octet-stream'),
            'size' => (int) ($raw['size'] ?? 0),
            'sha256' => isset($raw['sha256']) && is_string($raw['sha256']) && $raw['sha256'] !== '' ? strtolower($raw['sha256']) : null,
            'driveAccount' => (string) ($raw['drive_account'] ?? 'gdrive_primary'),
            'driveFileId' => (string) ($raw['drive_file_id'] ?? $id),
            'modifiedTime' => $updatedAt,
            'archived' => ! empty($raw['archived']),
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

    private function inferWorkspaceId(string $normalizedPath): ?string
    {
        $parts = explode('/', $normalizedPath);
        if (count($parts) >= 2 && $parts[0] === 'media') {
            return $parts[1];
        }
        if (count($parts) >= 3 && $parts[0] === 'tmp' && $parts[1] === 'media') {
            return $parts[2];
        }

        return null;
    }

    private function inferCategory(string $normalizedPath): string
    {
        if (str_starts_with($normalizedPath, 'backups/')) {
            return 'backup';
        }
        if (str_contains($normalizedPath, '/derived/')) {
            return 'derived';
        }
        if (str_starts_with($normalizedPath, 'tmp/')) {
            return 'temp';
        }

        return 'media';
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

    private function readLocalCache(string $id, ?string $expectedSha256): ?string
    {
        if ($this->localCacheDir === null || $this->localCacheDir === '') {
            return null;
        }

        $file = rtrim($this->localCacheDir, '/').'/'.preg_replace('/[^a-zA-Z0-9_-]/', '', $id).'.bin';
        if (! is_file($file)) {
            return null;
        }

        $bytes = @file_get_contents($file);
        if (! is_string($bytes)) {
            return null;
        }

        if ($expectedSha256 !== null && $expectedSha256 !== '' && hash('sha256', $bytes) !== strtolower($expectedSha256)) {
            @unlink($file);

            return null;
        }

        @touch($file);

        return $bytes;
    }

    private function writeLocalCache(string $id, string $bytes): void
    {
        if ($this->localCacheDir === null || $this->localCacheDir === '') {
            return;
        }

        // Do not cache files larger than 25% of the total local cache ceiling
        if (strlen($bytes) > intdiv($this->localCacheMaxBytes, 4)) {
            return;
        }

        try {
            if (! is_dir($this->localCacheDir)) {
                @mkdir($this->localCacheDir, 0775, true);
            }
            $file = rtrim($this->localCacheDir, '/').'/'.preg_replace('/[^a-zA-Z0-9_-]/', '', $id).'.bin';
            @file_put_contents($file, $bytes, LOCK_EX);
            $this->enforceLocalCacheBudget();
        } catch (Throwable) {
            // best-effort local cache
        }
    }

    private function evictLocalCacheFile(string $id): void
    {
        if ($this->localCacheDir === null || $this->localCacheDir === '') {
            return;
        }
        $file = rtrim($this->localCacheDir, '/').'/'.preg_replace('/[^a-zA-Z0-9_-]/', '', $id).'.bin';
        @unlink($file);
    }

    public function enforceLocalCacheBudget(): int
    {
        if ($this->localCacheDir === null || $this->localCacheDir === '' || ! is_dir($this->localCacheDir)) {
            return 0;
        }

        $files = glob(rtrim($this->localCacheDir, '/').'/*.bin') ?: [];
        $entries = [];
        $total = 0;

        foreach ($files as $f) {
            if (is_file($f)) {
                $size = (int) @filesize($f);
                $mtime = (int) @filemtime($f);
                $total += $size;
                $entries[] = ['path' => $f, 'size' => $size, 'mtime' => $mtime];
            }
        }

        if ($total <= $this->localCacheMaxBytes) {
            return 0;
        }

        usort($entries, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
        $evicted = 0;
        foreach ($entries as $entry) {
            if ($total <= $this->localCacheMaxBytes) {
                break;
            }
            if (@unlink($entry['path'])) {
                $total -= $entry['size'];
                $evicted++;
            }
        }

        return $evicted;
    }
}
