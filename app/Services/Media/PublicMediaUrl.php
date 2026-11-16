<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\Platform;
use App\Models\PostMedia;
use App\Support\FileStorage;
use Illuminate\Support\Facades\URL;

/**
 * Resolves a publicly reachable HTTPS URL for a stored media file, for
 * platforms (Instagram, Threads) that publish by handing Meta a URL it
 * fetches server-side rather than accepting an uploaded byte stream.
 *
 * Two things make this different from the URL the browser loads (PostMedia::url):
 *
 *  - It must be absolute. The `public` disk is configured host-relative
 *    ('url' => '/storage') so an <img> resolves it against the current origin;
 *    Meta fetches from its own servers and has no origin to resolve against.
 *  - It must point at bytes in a format the platform accepts. The byte-upload
 *    connectors re-encode in flight, but Meta reads whatever is on disk, so a
 *    non-conforming image is converted to a derived JPEG first.
 */
class PublicMediaUrl
{
    public function __construct(private readonly ImageToJpegConverter $converter) {}

    /**
     * @param  Platform|null  $platform  The destination whose accepted image formats apply.
     *                                   Omitted, the stored file is used as-is.
     *
     * @throws ImageConversionFailed
     */
    public function for(PostMedia $media, ?Platform $platform = null): string
    {
        $disk = $media->disk;
        $path = $media->path;

        if ($this->needsConversion($media, $platform)) {
            $converted = $this->converter->convert($media);
            $disk = $converted->disk;
            $path = $converted->path;
            if ($disk === 'ebin_cloud') {
                return URL::temporarySignedRoute('media.signed', now()->addHours(2), [
                    'mediaId' => $media->id,
                    'variant' => 'derived_jpg',
                ]);
            }
        } elseif ($disk === 'ebin_cloud') {
            $r2Bucket = trim((string) config('filesystems.disks.r2.bucket', ''));
            if ($r2Bucket !== '') {
                $stagedPath = 'published/'.ltrim($path, '/');
                $targetStorage = FileStorage::disk('r2');
                if (! $targetStorage->exists($stagedPath)) {
                    $bytes = FileStorage::disk('ebin_cloud')->get($path);
                    if ($bytes === null || $bytes === '') {
                        throw new ImageConversionFailed('Could not stage media from Ebin Cloud for publishing.');
                    }
                    $targetStorage->put($stagedPath, $bytes);
                }

                return $this->absolute(FileStorage::url($stagedPath, 'r2'));
            }

            return URL::temporarySignedRoute('media.signed', now()->addHours(2), [
                'mediaId' => $media->id,
            ]);
        } elseif ($disk === 'google') {
            $publishingDisk = FileStorage::publishingDiskName();
            if ($publishingDisk !== 'google') {
                $stagedPath = 'published/'.ltrim($path, '/');
                $targetStorage = FileStorage::disk($publishingDisk);
                if (! $targetStorage->exists($stagedPath)) {
                    $bytes = FileStorage::disk('google')->get($path);
                    if ($bytes === null || $bytes === '') {
                        throw new ImageConversionFailed('Could not stage media from Google Drive for publishing.');
                    }
                    $targetStorage->put($stagedPath, $bytes);
                }
                $disk = $publishingDisk;
                $path = $stagedPath;
            }
        }

        return $this->absolute(FileStorage::url($path, $disk));
    }

    private function needsConversion(PostMedia $media, ?Platform $platform): bool
    {
        return $platform !== null
            && ! $media->isVideo()
            && ! in_array($media->mime, $platform->allowedMime(), true);
    }

    /**
     * Host-relative disk URLs are correct for the browser but unfetchable by Meta, so
     * resolve them against the app's own origin.
     */
    private function absolute(string $url): string
    {
        return parse_url($url, PHP_URL_SCHEME) === null ? URL::to($url) : $url;
    }
}
