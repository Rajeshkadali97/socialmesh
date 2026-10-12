<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\PostStatus;

final class ShareReview
{
    /** Statuses where a client's approval is still meaningful (not yet out the door). */
    public const REVIEWABLE = [PostStatus::Draft, PostStatus::Scheduled];

    public const DECISIONS = ['approved', 'changes_requested'];
}
