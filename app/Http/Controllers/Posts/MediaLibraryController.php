<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostStatus;
use App\Http\Controllers\Concerns\StoresPresignedVideo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\SignVideoUploadRequest;
use App\Http\Requests\Post\StorePostMediaRequest;
use App\Http\Requests\Post\StoreVideoRequest;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Posts\MediaStorageService;
use App\Services\Storage\EbinCloudFilesystemAdapter;
use App\Support\FileStorage;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MediaLibraryController extends Controller
{
    use StoresPresignedVideo;

    public function __construct(private readonly MediaStorageService $media) {}

    public function index(Request $request): Response|JsonResponse
    {
        $user = $request->user();
        abort_if($user === null || $user->current_workspace_id === null, 403);

        $workspaceId = (string) $user->current_workspace_id;
        $kind = $request->string('kind')->toString();
        $state = $request->string('state')->toString();
        $campaign = $request->string('campaign')->toString();
        $q = $request->string('q')->trim()->toString();

        $baseQuery = PostMedia::withoutGlobalScopes()
            ->with(['post:id,base_text,status,scheduled_at,published_at,campaign'])
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at');

        // Deduplicate by (disk, path) so the library shows each stored asset once,
        // along with all posts that reference that underlying path.
        $allWorkspaceMedia = (clone $baseQuery)
            ->orderByDesc('created_at')
            ->get();

        $referencesByPath = [];
        foreach ($allWorkspaceMedia as $row) {
            $key = $row->disk.':'.$row->path;
            $referencesByPath[$key] ??= [];
            if ($row->post !== null) {
                $referencesByPath[$key][$row->post->id] = [
                    'id' => $row->post->id,
                    'excerpt' => Str::limit((string) $row->post->base_text, 60),
                    'status' => $row->post->status->value,
                    'scheduled_at' => $row->post->scheduled_at?->toIso8601String(),
                ];
            }
        }

        $seenKeys = [];
        $items = [];
        $counts = [
            'all' => 0,
            'image' => 0,
            'video' => 0,
            'archived' => 0,
        ];

        foreach ($allWorkspaceMedia as $row) {
            $key = $row->disk.':'.$row->path;
            if (isset($seenKeys[$key])) {
                continue;
            }
            $seenKeys[$key] = true;

            $isArchived = $row->archived_at !== null;
            if ($isArchived) {
                $counts['archived']++;
            } else {
                $counts['all']++;
                if ($row->kind === 'video') {
                    $counts['video']++;
                } else {
                    $counts['image']++;
                }
            }

            if ($state === 'archived' && ! $isArchived) {
                continue;
            }
            if ($state !== 'archived' && $isArchived) {
                continue;
            }
            if ($kind !== '' && $kind !== 'all' && $row->kind !== $kind) {
                continue;
            }
            if ($campaign !== '' && $row->campaign !== $campaign) {
                continue;
            }
            $filename = $row->original_filename ?? basename($row->path);
            if ($q !== '' && ! Str::contains(strtolower($filename.' '.($row->alt_text ?? '').' '.($row->campaign ?? '')), strtolower($q))) {
                continue;
            }

            $refs = array_values($referencesByPath[$key] ?? []);
            $view = $row->toView();
            $items[] = [
                ...$view,
                'created_at' => $row->created_at?->toIso8601String(),
                'archived_at' => $row->archived_at?->toIso8601String(),
                'disk' => $row->disk,
                'reference_count' => count($refs),
                'referenced_posts' => $refs,
            ];
        }

        $campaigns = $allWorkspaceMedia
            ->pluck('campaign')
            ->filter(static fn (?string $c): bool => $c !== null && $c !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'items' => $items,
                'campaigns' => $campaigns,
                'counts' => $counts,
            ]);
        }

        return Inertia::render('media/index', [
            'items' => $items,
            'campaigns' => $campaigns,
            'counts' => $counts,
            'filters' => [
                'kind' => $kind ?: 'all',
                'state' => $state ?: 'active',
                'campaign' => $campaign,
                'q' => $q,
            ],
        ]);
    }

    public function store(StorePostMediaRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null || $user->current_workspace_id === null, 403);

        $media = $this->media->store(
            (string) $user->current_workspace_id,
            $request->file('file'),
            $request->validated('alt_text'),
            $request->string('campaign')->toString() ?: null,
        );

        return response()->json(['media' => $media->toView()], 201);
    }

    public function signVideo(SignVideoUploadRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null || $user->current_workspace_id === null, 403);

        return $this->signVideoUpload((string) $user->current_workspace_id);
    }

    public function storeVideo(StoreVideoRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null || $user->current_workspace_id === null, 403);

        return $this->storeUploadedVideo(
            $request->validated(),
            (string) $user->current_workspace_id,
            ['source' => 'media_library'],
            $request->string('campaign')->toString() ?: null,
        );
    }

    /**
     * Clone an existing Media Library asset into a new PostMedia record for a draft/post
     * without re-uploading or duplicating the underlying cloud storage file.
     */
    public function attachToPost(Request $request, Post $post, PostMedia $media): JsonResponse
    {
        abort_unless($media->workspace_id === $post->workspace_id, 404);
        abort_unless($post->status->isEditable(), 422, 'This post can no longer be edited.');

        // If the asset is already an unattached orphan in this workspace, reuse it directly
        if ($media->post_id === null && $media->direct_message_id === null) {
            return response()->json(['media' => $media->toView()]);
        }

        // Otherwise create a lightweight reference row pointing to the exact same Ebin Cloud / storage path
        $cloned = PostMedia::create([
            'workspace_id' => $post->workspace_id,
            'uploaded_by_user_id' => Auth::id(),
            'post_id' => null,
            'disk' => $media->disk,
            'path' => $media->path,
            'original_filename' => $media->original_filename ?? basename($media->path),
            'source_disk' => $media->source_disk,
            'source_path' => $media->source_path,
            'drive_file_id' => $media->drive_file_id,
            'checksum' => $media->checksum,
            'campaign' => $post->campaign ?? $media->campaign,
            'storage_error' => null,
            'upload_status' => 'available',
            'processing_status' => 'ready',
            'kind' => $media->kind,
            'mime' => $media->mime,
            'size_bytes' => $media->size_bytes,
            'width' => $media->width,
            'height' => $media->height,
            'duration_seconds' => $media->duration_seconds,
            'alt_text' => $media->alt_text,
            'position' => 0,
            'edit_settings' => $media->edit_settings,
        ]);

        return response()->json(['media' => $cloned->toView()], 201);
    }

    public function toggleArchive(Request $request, PostMedia $media): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_if($user === null || $media->workspace_id !== $user->current_workspace_id, 404);

        $archiving = $media->archived_at === null;
        $nextTimestamp = $archiving ? now() : null;

        PostMedia::withoutGlobalScopes()
            ->where('workspace_id', $media->workspace_id)
            ->where('disk', $media->disk)
            ->where('path', $media->path)
            ->update(['archived_at' => $nextTimestamp]);

        if ($media->disk === 'ebin_cloud' || $media->source_disk === 'ebin_cloud') {
            try {
                $storage = FileStorage::disk('ebin_cloud');
                if ($storage instanceof LaravelFilesystemAdapter && $storage->getAdapter() instanceof EbinCloudFilesystemAdapter) {
                    $storage->getAdapter()->getClient()->setArchived($media->path, $archiving);
                }
            } catch (Throwable) {
                // non-fatal
            }
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['archived' => $archiving]);
        }

        return back()->with('success', $archiving ? 'Media archived.' : 'Media restored from archive.');
    }

    public function destroy(Request $request, PostMedia $media): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_if($user === null || $media->workspace_id !== $user->current_workspace_id, 404);

        $siblings = PostMedia::withoutGlobalScopes()
            ->with('post:id,status')
            ->where('workspace_id', $media->workspace_id)
            ->where('disk', $media->disk)
            ->where('path', $media->path)
            ->whereNull('deleted_at')
            ->get();

        // Guard: refuse to delete media from the library if any active draft or scheduled/publishing post uses it
        foreach ($siblings as $row) {
            if ($row->post !== null && in_array($row->post->status, [
                PostStatus::Draft,
                PostStatus::Scheduled,
                PostStatus::Publishing,
            ], true)) {
                $msg = 'Cannot delete media while it is attached to an active draft or scheduled post.';
                if ($request->wantsJson() && ! $request->header('X-Inertia')) {
                    return response()->json(['message' => $msg], 422);
                }

                return back()->with('error', $msg);
            }
        }

        foreach ($siblings as $row) {
            $row->delete();
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('success', 'Media deleted.');
    }
}
