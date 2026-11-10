<?php

declare(strict_types=1);

namespace App\Services\Storage;

use RuntimeException;
use Throwable;

class GoogleDriveStorageException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorKind = 'storage_error',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function missingCredentials(): self
    {
        return new self(
            'Google Drive storage is not connected. Add Google Drive credentials in settings or .env.',
            'missing_credentials',
            401,
        );
    }

    public static function expiredOrRevokedToken(string $detail = ''): self
    {
        $suffix = $detail !== '' ? " ({$detail})" : '';

        return new self(
            "Google Drive authorization expired or was revoked{$suffix}. Reconnect Google Drive in settings.",
            'token_expired',
            401,
        );
    }

    public static function quotaExceeded(): self
    {
        return new self(
            'Google Drive storage quota is full. Free up space in the connected Google Drive account.',
            'quota_exceeded',
            507,
        );
    }

    public static function rateLimited(): self
    {
        return new self(
            'Google Drive API rate limit reached. The transfer will be retried automatically.',
            'rate_limited',
            429,
        );
    }

    public static function transferFailed(string $reason, int $status = 500, ?Throwable $previous = null): self
    {
        return new self(
            "Google Drive transfer failed: {$reason}",
            'transfer_failed',
            $status,
            $previous,
        );
    }
}
