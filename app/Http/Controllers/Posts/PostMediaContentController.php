<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Models\PostMedia;
use App\Services\Media\DerivedMedia;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PostMediaContentController extends Controller
{
    /**
     * Stream a stored media file back through the app on its own origin.
     *
     * In production the bytes live on an S3-compatible bucket that serves them
     * via presigned URLs with no CORS headers. That is fine for plain <img>/<video>
     * display, but the client-side image/video editors must `fetch()` the source
     * into a canvas — a cross-origin request the bucket rejects. Proxying the read
     * here gives the editor a same-origin URL, sidestepping bucket CORS entirely.
     *
     * `variant=source` serves the retained pre-edit original (present only on
     * beautified media); the default serves the composed/main file.
     */
    public function show(Request $request, PostMedia $media): StreamedResponse
    {
        if ($request->query('variant') === 'source') {
            abort_if($media->source_path === null, 404);
            $disk = $media->source_disk ?? $media->disk;
            $path = $media->source_path;
        } else {
            $disk = $media->disk;
            $path = $media->path;
        }

        $storage = FileStorage::disk($disk);
        if (! $storage->exists($path)) {
            // If primary disk file is missing and a Google Drive or Ebin Cloud archive copy exists,
            // fall back to reading from the archive disk so archived assets remain previewable.
            if (! in_array($disk, ['google', 'ebin_cloud'], true) && in_array($media->source_disk, ['google', 'ebin_cloud'], true) && $media->source_path !== null) {
                $storage = FileStorage::disk($media->source_disk);
                $path = $media->source_path;
                abort_unless($storage->exists($path), 404);
            } elseif ($disk !== 'ebin_cloud' && $media->drive_file_id !== null && (string) config('filesystems.disks.ebin_cloud.service_key', '') !== '') {
                $storage = FileStorage::disk('ebin_cloud');
                abort_unless($storage->exists($path), 404);
            } else {
                abort(404);
            }
        }

        return $storage->response($path, null, [
            // Authenticated per-user response — never store in a shared cache.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Serve a short-lived signed media URL (`?expires=...&signature=...`) for
     * external platform fetchers (Instagram Graph API, Threads API) so Ebin Cloud
     * and Google Drive assets remain private by default while still allowing
     * scoped, expiring external delivery during publishing.
     */
    public function showSigned(Request $request, string $mediaId): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $media = PostMedia::withoutGlobalScopes()->whereKey($mediaId)->firstOrFail();

        $variant = (string) $request->query('variant', '');
        if ($variant === 'derived_jpg') {
            $path = DerivedMedia::path($media, 'jpg');
            $disk = $media->disk;
        } elseif ($variant === 'derived_mp4') {
            $path = DerivedMedia::path($media, 'mp4');
            $disk = $media->disk;
        } else {
            $disk = $media->disk;
            $path = $media->path;
        }

        $storage = FileStorage::disk($disk);
        if (! $storage->exists($path)) {
            if (in_array($media->source_disk, ['google', 'ebin_cloud'], true) && $media->source_path !== null) {
                $storage = FileStorage::disk($media->source_disk);
                $path = $media->source_path;
                abort_unless($storage->exists($path), 404);
            } else {
                abort(404);
            }
        }

        return $storage->response($path, null, [
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
