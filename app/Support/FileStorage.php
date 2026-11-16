<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class FileStorage
{
    public static function diskName(): string
    {
        return (string) config('filesystems.default');
    }

    /**
     * Presigned upload target for a video. Object-storage disks return a native
     * direct-to-storage PUT (the app never touches the bytes). A local disk
     * returns a relative signed URL to the streaming upload route, which copies
     * the body to disk without buffering it in memory — see StreamedUploadController.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public static function temporaryVideoUploadUrl(string $key, DateTimeInterface $expiration): array
    {
        $disk = self::diskName();
        $driver = (string) config("filesystems.disks.{$disk}.driver");

        if ($driver === 'local' || $driver === 'google' || $driver === 'ebin_cloud') {
            return [
                'url' => URL::temporarySignedRoute('uploads.stream', $expiration, ['path' => $key], absolute: false),
                'headers' => [],
            ];
        }

        /** @var array{url: string, headers: array<string, string>} $presigned */
        $presigned = Storage::disk($disk)->temporaryUploadUrl($key, $expiration);

        return $presigned;
    }

    public static function publicImageDiskName(): string
    {
        return (string) (config('filesystems.public_images') ?: self::diskName());
    }

    /**
     * Resolve the public publishing disk (Cloudflare R2, S3 public-images, or local public)
     * used when a platform like Instagram or Threads requires a publicly reachable HTTPS URL
     * and the asset lives on a private archive disk such as Google Drive.
     */
    public static function publishingDiskName(): string
    {
        $configured = (string) (config('filesystems.publishing_disk') ?: config('filesystems.public_images') ?: '');
        if ($configured !== '' && ! in_array($configured, ['google', 'ebin_cloud'], true)) {
            return $configured;
        }

        $r2Bucket = trim((string) config('filesystems.disks.r2.bucket', ''));
        if ($r2Bucket !== '') {
            return 'r2';
        }

        $default = self::diskName();

        return in_array($default, ['google', 'ebin_cloud'], true) ? 'public' : $default;
    }

    /**
     * Optional long-term archive disk (e.g. `google` for Google Drive).
     */
    public static function archiveDiskName(): ?string
    {
        $archive = config('filesystems.archive_disk');

        return is_string($archive) && $archive !== '' ? $archive : null;
    }

    public static function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? self::diskName());
    }

    public static function url(string $path, ?string $disk = null): string
    {
        $disk ??= self::diskName();

        if (config("filesystems.disks.{$disk}.visibility") === 'public') {
            return Storage::disk($disk)->url($path);
        }

        return Storage::disk($disk)->temporaryUrl($path, now()->addHours(6));
    }
}
