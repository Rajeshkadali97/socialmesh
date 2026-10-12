<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Models\Post;
use App\Models\PostShare;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class ShareService
{
    /**
     * @return array{0: PostShare, 1: string}
     */
    public function mint(Post $post, User $user, ?CarbonInterface $expiresAt): array
    {
        $token = Str::random(43);
        $share = PostShare::query()->create([
            'post_id' => $post->id,
            'created_by' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        return [$share, $token];
    }

    public function resolveActive(string $token): ?PostShare
    {
        $share = PostShare::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $share?->isActive() === true ? $share : null;
    }

    public function url(string $token): string
    {
        return url("/share/{$token}");
    }

    /**
     * Record the client's verdict on a shared post. A later verdict from the same
     * link replaces the earlier one, so a client can approve after a revision.
     */
    public function decide(PostShare $share, string $decision, ?string $reviewerName, ?string $note): PostShare
    {
        $share->forceFill([
            'decision' => $decision,
            'decision_note' => $decision === 'changes_requested' ? $note : null,
            'reviewer_name' => $reviewerName,
            'decided_at' => now(),
        ])->save();

        return $share;
    }
}
