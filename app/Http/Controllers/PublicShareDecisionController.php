<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Posts\ShareReview;
use App\Services\Posts\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicShareDecisionController extends Controller
{
    public function store(Request $request, string $token, ShareService $shares): JsonResponse
    {
        $share = $shares->resolveActive($token);
        abort_if($share === null, 404);

        $post = $share->post()->withoutGlobalScopes()->first();
        abort_if($post === null, 404);

        if (! in_array($post->status, ShareReview::REVIEWABLE, true)) {
            return response()->json(['message' => 'This post is no longer awaiting review.'], 422);
        }

        $validated = $request->validate([
            'decision' => ['required', Rule::in(ShareReview::DECISIONS)],
            'note' => ['nullable', 'string', 'max:1000', 'required_if:decision,changes_requested'],
            'reviewer_name' => ['nullable', 'string', 'max:80'],
        ], [
            'note.required_if' => 'Tell us what to change.',
        ]);

        $share = $shares->decide(
            $share,
            $validated['decision'],
            isset($validated['reviewer_name']) ? trim($validated['reviewer_name']) : null,
            isset($validated['note']) ? trim($validated['note']) : null,
        );

        return response()->json([
            'decision' => $share->decision,
            'note' => $share->decision_note,
            'reviewer_name' => $share->reviewer_name,
            'decided_at' => $share->decided_at?->toIso8601String(),
        ]);
    }
}
