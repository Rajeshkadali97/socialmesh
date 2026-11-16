<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use App\Services\Media\DerivedMedia;
use App\Support\FileStorage;
use Database\Factories\PostMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $uploaded_by_user_id
 * @property string|null $post_id
 * @property string|null $direct_message_id
 * @property string $disk
 * @property string $path
 * @property string|null $original_filename
 * @property string $mime
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 * @property int $position
 * @property string $kind
 * @property int|null $duration_seconds
 * @property string|null $source_disk
 * @property string|null $source_path
 * @property string|null $drive_file_id
 * @property string|null $checksum
 * @property string|null $campaign
 * @property string|null $storage_error
 * @property string $upload_status
 * @property string $processing_status
 * @property array<string, mixed>|null $edit_settings
 * @property Carbon|null $archived_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'workspace_id',
    'uploaded_by_user_id',
    'post_id',
    'direct_message_id',
    'disk',
    'path',
    'original_filename',
    'mime',
    'size_bytes',
    'checksum',
    'campaign',
    'storage_error',
    'upload_status',
    'processing_status',
    'width',
    'height',
    'alt_text',
    'position',
    'kind',
    'duration_seconds',
    'source_disk',
    'source_path',
    'drive_file_id',
    'edit_settings',
    'archived_at',
])]
#[Table(name: 'post_media')]
class PostMedia extends Model
{
    /** @use HasFactory<PostMediaFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope, SoftDeletes;

    /**
     * In-memory default so a row freshly created without an explicit kind still
     * serializes as an image (Eloquent does not hydrate DB defaults after create).
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'kind' => 'image',
        'upload_status' => 'available',
        'processing_status' => 'ready',
    ];

    /**
     * Delete the backing file(s) when the row is removed, so storage doesn't
     * accumulate orphans. Covers both the composed file and a retained source.
     * Reference-aware: if another active PostMedia row still references the same
     * underlying storage path, the underlying file is preserved.
     * Original Google Drive / Ebin Cloud files are never deleted automatically
     * on soft-delete.
     */
    protected static function booted(): void
    {
        static::deleting(function (PostMedia $media): void {
            $otherReferences = self::withTrashed()
                ->withoutGlobalScopes()
                ->where('id', '!=', $media->id)
                ->where('disk', $media->disk)
                ->where('path', $media->path)
                ->whereNull('deleted_at')
                ->exists();

            if (! $otherReferences && ! in_array($media->disk, ['google', 'ebin_cloud'], true)) {
                FileStorage::disk($media->disk)->delete($media->path);

                // Publish-time format conversions (JPEG for Meta, MP4 for GIFs) live
                // beside the original and would otherwise be left behind.
                FileStorage::disk($media->disk)->delete(DerivedMedia::pathsFor($media));
            } elseif (! $otherReferences && $media->disk === 'ebin_cloud' && $media->isForceDeleting()) {
                FileStorage::disk('ebin_cloud')->delete($media->path);
            }

            $sourceDisk = $media->source_disk ?? $media->disk;
            if ($media->source_path !== null && ! in_array($sourceDisk, ['google', 'ebin_cloud'], true)) {
                $otherSourceRefs = self::withTrashed()
                    ->withoutGlobalScopes()
                    ->where('id', '!=', $media->id)
                    ->where('source_path', $media->source_path)
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $otherSourceRefs) {
                    FileStorage::disk($sourceDisk)->delete($media->source_path);
                }
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edit_settings' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function lifecycleState(): string
    {
        if ($this->trashed()) {
            return 'deleted';
        }
        if ($this->storage_error !== null && $this->storage_error !== '') {
            return 'failed';
        }
        if ($this->archived_at !== null) {
            return 'archived';
        }
        if ($this->upload_status === 'uploading') {
            return 'uploading';
        }
        if ($this->processing_status === 'processing') {
            return 'processing';
        }
        if ($this->post_id !== null && $this->post !== null) {
            return match ($this->post->status->value) {
                'draft' => 'attached_to_draft',
                'scheduled', 'publishing' => 'attached_to_scheduled_post',
                'published', 'partial' => 'used_in_published_post',
                default => 'available',
            };
        }

        return 'available';
    }

    public function url(): string
    {
        return $this->resolveUrl($this->disk, $this->path);
    }

    public function source_url(): ?string
    {
        if ($this->source_path === null) {
            return null;
        }

        return $this->resolveUrl($this->source_disk ?? $this->disk, $this->source_path);
    }

    /**
     * Resolve a browser-loadable URL for a stored file. Public-visibility disks
     * (images) get a permanent public URL; private disks (videos live on the
     * default disk — local-serve in dev, a private S3 bucket in production) need
     * a signed, expiring URL, otherwise the serve route / bucket rejects a
     * direct GET with 403. Google Drive and Ebin Cloud media always resolve through
     * the authenticated same-origin `media.raw` proxy route.
     */
    private function resolveUrl(string $disk, string $path): string
    {
        if (in_array($disk, ['google', 'ebin_cloud'], true)) {
            return $path === $this->source_path
                ? route('media.raw', ['media' => $this, 'variant' => 'source'])
                : route('media.raw', $this);
        }

        return FileStorage::url($path, $disk);
    }

    /**
     * `edit_url` / `source_edit_url` are same-origin proxy URLs the canvas
     * editors fetch instead of the display URLs, whose storage origin may omit
     * CORS headers. `source_edit_url` is null when no pre-edit source is kept.
     *
     * @return array{id: string, url: string, mime: string, kind: string, duration_seconds: int|null, alt_text: string|null, position: int, edit_settings: array<string, mixed>|null, source_url: string|null, edit_url: string, source_edit_url: string|null, drive_file_id: string|null, checksum: string|null, campaign: string|null, storage_error: string|null, original_filename: string|null, size_bytes: int, lifecycle_state: string}
     */
    public function toView(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'mime' => $this->mime,
            'kind' => $this->kind,
            'duration_seconds' => $this->duration_seconds,
            'alt_text' => $this->alt_text,
            'position' => $this->position,
            'edit_settings' => $this->edit_settings,
            'source_url' => $this->source_url(),
            'edit_url' => route('media.raw', $this),
            'source_edit_url' => $this->source_path === null
                ? null
                : route('media.raw', ['media' => $this, 'variant' => 'source']),
            'drive_file_id' => $this->drive_file_id,
            'checksum' => $this->checksum,
            'campaign' => $this->campaign,
            'storage_error' => $this->storage_error,
            'original_filename' => $this->original_filename ?? basename($this->path),
            'size_bytes' => (int) $this->size_bytes,
            'lifecycle_state' => $this->lifecycleState(),
        ];
    }

    public function isVideo(): bool
    {
        return $this->kind === 'video';
    }

    /**
     * Whether this image is attach-only rather than editable. The raster
     * beautifier would flatten an animation to a single frame, so animated media
     * must never open the editor. Animation isn't visible from the mime alone —
     * the GIF browser stores a "GIF" as the larger `image/webp` variant, the same
     * mime the beautifier emits — so a WebP is treated as editable only when it is
     * a beautifier output (it carries edit_settings). GIFs are always animated.
     * Mirrors the client's isAttachOnlyImage() (resources/js/lib/compose/media-rules.ts).
     */
    public function isAttachOnlyImage(): bool
    {
        if ($this->mime === 'image/gif') {
            return true;
        }

        return $this->mime === 'image/webp' && $this->edit_settings === null;
    }
}
