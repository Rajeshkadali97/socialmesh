<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\InstanceSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolves and refreshes Google OAuth access tokens for Google Drive storage.
 *
 * Credentials can come from either the `google` filesystem disk config (.env)
 * or encrypted instance settings (`google_drive.oauth`) saved via the admin OAuth flow.
 * Never logs or exposes client secrets or refresh tokens.
 */
class GoogleDriveTokenProvider
{
    public const string SETTING_KEY = 'google_drive.oauth';

    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const string CACHE_KEY = 'google_drive:access_token';

    /**
     * @param  array<string, mixed>  $diskConfig
     */
    public function __construct(private readonly array $diskConfig = []) {}

    /**
     * Return a valid Bearer access token, refreshing automatically when expired.
     *
     * @throws GoogleDriveStorageException
     */
    public function accessToken(bool $forceRefresh = false): string
    {
        $configuredAccessToken = trim((string) ($this->diskConfig['access_token'] ?? ''));
        if ($configuredAccessToken !== '' && ! $forceRefresh) {
            return $configuredAccessToken;
        }

        if (! $forceRefresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $credentials = $this->resolveCredentials();
        if ($credentials === null) {
            throw GoogleDriveStorageException::missingCredentials();
        }

        return Cache::lock('google-drive-token-refresh', 15)->block(10, function () use ($credentials, $forceRefresh): string {
            if (! $forceRefresh) {
                $cached = Cache::get(self::CACHE_KEY);
                if (is_string($cached) && $cached !== '') {
                    return $cached;
                }
            }

            try {
                $response = Http::asForm()
                    ->timeout(15)
                    ->retry(2, 250, fn (Throwable $e): bool => $e instanceof ConnectionException)
                    ->post(self::TOKEN_URL, [
                        'client_id' => $credentials['client_id'],
                        'client_secret' => $credentials['client_secret'],
                        'refresh_token' => $credentials['refresh_token'],
                        'grant_type' => 'refresh_token',
                    ]);
            } catch (Throwable $e) {
                throw GoogleDriveStorageException::transferFailed('Could not reach Google OAuth token endpoint.', 503, $e);
            }

            if ($response->status() === 400 || $response->status() === 401) {
                $error = (string) ($response->json('error_description') ?? $response->json('error') ?? 'invalid_grant');

                throw GoogleDriveStorageException::expiredOrRevokedToken($error);
            }

            if (! $response->successful()) {
                throw GoogleDriveStorageException::transferFailed(
                    'Token refresh returned HTTP '.$response->status().'.',
                    $response->status(),
                );
            }

            $token = trim((string) $response->json('access_token', ''));
            if ($token === '') {
                throw GoogleDriveStorageException::transferFailed('Google OAuth response did not include an access_token.');
            }

            $expiresIn = max(60, ((int) $response->json('expires_in', 3600)) - 120);
            Cache::put(self::CACHE_KEY, $token, now()->addSeconds($expiresIn));

            return $token;
        });
    }

    public function clearCachedToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function isConfigured(): bool
    {
        if (trim((string) ($this->diskConfig['access_token'] ?? '')) !== '') {
            return true;
        }

        return $this->resolveCredentials() !== null;
    }

    /**
     * Save encrypted OAuth refresh token and metadata after the admin OAuth callback.
     */
    public static function storeConnectedAccount(
        string $refreshToken,
        ?string $email = null,
        ?string $rootFolderId = null,
    ): void {
        $existing = (new self)->readStoredSetting();
        $payload = [
            'refresh_token' => Crypt::encryptString($refreshToken),
            'email' => $email ?? ($existing['email'] ?? null),
            'root_folder_id' => $rootFolderId ?? ($existing['root_folder_id'] ?? null),
            'connected_at' => now()->toIso8601String(),
            'last_verified_at' => now()->toIso8601String(),
            'last_error' => null,
            'quota_used_bytes' => $existing['quota_used_bytes'] ?? null,
            'quota_limit_bytes' => $existing['quota_limit_bytes'] ?? null,
        ];

        InstanceSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $payload],
        );
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Record the result of a live Google Drive health/verification probe.
     */
    public static function recordHealthCheck(
        bool $healthy,
        ?string $error = null,
        ?string $email = null,
        ?string $rootFolderId = null,
        ?int $quotaUsedBytes = null,
        ?int $quotaLimitBytes = null,
    ): void {
        $existing = (new self)->readStoredSetting();
        $payload = [
            ...$existing,
            'last_verified_at' => $healthy ? now()->toIso8601String() : ($existing['last_verified_at'] ?? null),
            'last_error' => $healthy ? null : $error,
        ];

        if ($email !== null && $email !== '') {
            $payload['email'] = $email;
        }
        if ($rootFolderId !== null && $rootFolderId !== '') {
            $payload['root_folder_id'] = $rootFolderId;
        }
        if ($quotaUsedBytes !== null) {
            $payload['quota_used_bytes'] = $quotaUsedBytes;
        }
        if ($quotaLimitBytes !== null) {
            $payload['quota_limit_bytes'] = $quotaLimitBytes;
        }

        InstanceSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $payload],
        );
    }

    public static function disconnect(): void
    {
        InstanceSetting::query()->where('key', self::SETTING_KEY)->delete();
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Summary safe for rendering in the admin UI (never includes tokens or secrets).
     *
     * @return array{connected: bool, source: string|null, email: string|null, root_folder_id: string, root_folder_name: string, archive_enabled: bool, connected_at: string|null, last_verified_at: string|null, last_error: string|null, quota_used_bytes: int|null, quota_limit_bytes: int|null, health: string}
     */
    public function status(): array
    {
        $stored = $this->readStoredSetting();
        $envRefresh = trim((string) ($this->diskConfig['refresh_token'] ?? config('services.google_drive.refresh_token', '')));
        $envAccess = trim((string) ($this->diskConfig['access_token'] ?? config('filesystems.disks.google.access_token', '')));
        $clientId = trim((string) ($this->diskConfig['client_id'] ?? config('services.google_drive.client_id', '')));
        $clientSecret = trim((string) ($this->diskConfig['client_secret'] ?? config('services.google_drive.client_secret', '')));

        $hasStoredRefresh = isset($stored['refresh_token']) && is_string($stored['refresh_token']) && $stored['refresh_token'] !== '';
        $hasEnvRefresh = $envRefresh !== '' && $clientId !== '' && $clientSecret !== '';

        $connected = $envAccess !== '' || ($hasStoredRefresh && $clientId !== '' && $clientSecret !== '') || $hasEnvRefresh;
        $source = null;
        if ($hasStoredRefresh && $clientId !== '' && $clientSecret !== '') {
            $source = 'oauth';
        } elseif ($hasEnvRefresh || $envAccess !== '') {
            $source = 'env';
        }

        $rootFolderId = (string) ($stored['root_folder_id']
            ?? $this->diskConfig['root_folder_id']
            ?? config('services.google_drive.root_folder_id', 'root'));

        $rootFolderName = trim((string) ($this->diskConfig['root_folder_name'] ?? config('filesystems.disks.google.root_folder_name', 'SocialMesh')));
        $lastError = isset($stored['last_error']) && is_string($stored['last_error']) && $stored['last_error'] !== ''
            ? $stored['last_error']
            : null;
        $lastVerifiedAt = isset($stored['last_verified_at']) && is_string($stored['last_verified_at'])
            ? $stored['last_verified_at']
            : null;

        $health = 'disconnected';
        if ($connected) {
            $health = $lastError !== null ? 'error' : ($lastVerifiedAt !== null ? 'healthy' : 'configured');
        }

        return [
            'connected' => $connected,
            'source' => $source,
            'email' => isset($stored['email']) && is_string($stored['email']) ? $stored['email'] : null,
            'root_folder_id' => $rootFolderId !== '' ? $rootFolderId : 'root',
            'root_folder_name' => $rootFolderName !== '' ? $rootFolderName : 'SocialMesh',
            'archive_enabled' => config('filesystems.archive_disk') === 'google' || config('filesystems.default') === 'google',
            'connected_at' => isset($stored['connected_at']) && is_string($stored['connected_at']) ? $stored['connected_at'] : null,
            'last_verified_at' => $lastVerifiedAt,
            'last_error' => $lastError,
            'quota_used_bytes' => isset($stored['quota_used_bytes']) && is_numeric($stored['quota_used_bytes']) ? (int) $stored['quota_used_bytes'] : null,
            'quota_limit_bytes' => isset($stored['quota_limit_bytes']) && is_numeric($stored['quota_limit_bytes']) ? (int) $stored['quota_limit_bytes'] : null,
            'health' => $health,
        ];
    }

    /**
     * @return array{client_id: string, client_secret: string, refresh_token: string}|null
     */
    private function resolveCredentials(): ?array
    {
        $clientId = trim((string) ($this->diskConfig['client_id'] ?? config('services.google_drive.client_id', '')));
        $clientSecret = trim((string) ($this->diskConfig['client_secret'] ?? config('services.google_drive.client_secret', '')));

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $refreshToken = trim((string) ($this->diskConfig['refresh_token'] ?? config('services.google_drive.refresh_token', '')));

        if ($refreshToken === '') {
            $stored = $this->readStoredSetting();
            $encrypted = $stored['refresh_token'] ?? null;
            if (is_string($encrypted) && $encrypted !== '') {
                try {
                    $refreshToken = Crypt::decryptString($encrypted);
                } catch (Throwable) {
                    $refreshToken = '';
                }
            }
        }

        if ($refreshToken === '') {
            return null;
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readStoredSetting(): array
    {
        try {
            $setting = InstanceSetting::query()->where('key', self::SETTING_KEY)->first();
            $val = $setting?->value;

            return is_array($val) ? $val : [];
        } catch (Throwable) {
            return [];
        }
    }
}
