<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Posts\ShareReview;
use App\Services\Posts\ShareService;
use App\Support\PublicPostView;
use Inertia\Inertia;
use Inertia\Response;

class PublicShareController extends Controller
{
    public function show(string $token, ShareService $shares): Response
    {
        $share = $shares->resolveActive($token);

        // Token proves authorization; bypass the workspace global scope for the
        // cross-workspace read. withoutGlobalScopes() removes the 'workspace'
        // named closure registered by HasWorkspaceScope.
        $post = $share
            ?->post()
            ->withoutGlobalScopes()
            ->with(['targets.account', 'media'])
            ->first();

        return Inertia::render('share/show', [
            'post' => $post !== null ? PublicPostView::make($post) : null,
            'review' => $post !== null ? [
                'can_review' => in_array($post->status, ShareReview::REVIEWABLE, true),
                'decision' => $share->decision,
                'note' => $share->decision_note,
                'reviewer_name' => $share->reviewer_name,
                'decided_at' => $share->decided_at?->toIso8601String(),
            ] : null,
        ]);
    }
}
