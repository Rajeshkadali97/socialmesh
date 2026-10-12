<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ConnectedAccountStatus;
use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * One row per workspace the user belongs to (an agency's clients), summarising
 * what needs attention without opening each workspace in turn.
 *
 * Workspace-owned models are normally filtered by the request's current
 * workspace, so every query here drops that scope and filters explicitly by the
 * user's own workspace ids. Aggregates run once for all workspaces, not per row.
 */
final class ClientOverview
{
    /** How far back a failed, partial or missed post still counts as needing attention. */
    public const int PROBLEM_WINDOW_DAYS = 14;

    /** Window used for "scheduled soon" and "published recently". */
    public const int WEEK_DAYS = 7;

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     role: string,
     *     timezone: string,
     *     is_current: bool,
     *     health: string,
     *     accounts: array{total: int, active: int, needs_attention: int, disabled: int},
     *     posts: array{scheduled: int, next_scheduled_at: string|null, next_7_days: int, problems: int, published_7_days: int},
     *     queue_slots: int,
     * }>
     */
    public static function make(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= Date::now()->toImmutable();

        /** @var Collection<int, WorkspaceMembership> $memberships */
        $memberships = $user->workspaceMemberships()->with('workspace')->get()
            ->filter(fn (WorkspaceMembership $membership): bool => $membership->workspace !== null)
            ->values();

        $ids = array_values(array_map(strval(...), $memberships->pluck('workspace_id')->all()));

        if ($ids === []) {
            return [];
        }

        $accounts = self::accountStats($ids);
        $posts = self::postStats($ids, $now);
        $slots = self::slotCounts($ids);

        $rows = $memberships->map(function (WorkspaceMembership $membership) use ($user, $accounts, $posts, $slots): array {
            $workspace = $membership->workspace;
            $account = $accounts[$workspace->id] ?? ['total' => 0, 'active' => 0, 'needs_attention' => 0, 'disabled' => 0];
            $post = $posts[$workspace->id] ?? ['scheduled' => 0, 'next_scheduled_at' => null, 'next_7_days' => 0, 'problems' => 0, 'published_7_days' => 0];

            return [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'role' => $membership->role->value,
                'timezone' => $workspace->timezone ?? 'UTC',
                'is_current' => $user->current_workspace_id === $workspace->id,
                'health' => self::health($account, $post),
                'accounts' => $account,
                'posts' => $post,
                'queue_slots' => $slots[$workspace->id] ?? 0,
            ];
        });

        $order = ['attention' => 0, 'empty' => 1, 'no_accounts' => 2, 'ok' => 3];

        return array_values($rows
            ->sort(fn (array $a, array $b): int => [$order[$a['health']], mb_strtolower($a['name'])] <=> [$order[$b['health']], mb_strtolower($b['name'])])
            ->all());
    }

    /**
     * `attention`: something is broken (an account to reconnect or a recent failed
     * post). `empty`: healthy but nothing is scheduled this week. `no_accounts`:
     * nothing connected yet. `ok` otherwise.
     *
     * @param  array{total: int, active: int, needs_attention: int, disabled: int}  $account
     * @param  array{scheduled: int, next_scheduled_at: string|null, next_7_days: int, problems: int, published_7_days: int}  $post
     */
    private static function health(array $account, array $post): string
    {
        if ($account['needs_attention'] > 0 || $post['problems'] > 0) {
            return 'attention';
        }

        if ($account['active'] === 0) {
            return 'no_accounts';
        }

        return $post['next_7_days'] === 0 ? 'empty' : 'ok';
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{total: int, active: int, needs_attention: int, disabled: int}>
     */
    private static function accountStats(array $ids): array
    {
        $needsAttention = ConnectedAccountStatus::NeedsAttention->value;

        return ConnectedAccount::withoutGlobalScopes()
            ->whereIn('workspace_id', $ids)
            ->selectRaw(
                'workspace_id,
                COUNT(*) AS total,
                SUM(CASE WHEN disabled_at IS NULL AND status <> ? THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN disabled_at IS NULL AND status = ? THEN 1 ELSE 0 END) AS needs_attention,
                SUM(CASE WHEN disabled_at IS NOT NULL THEN 1 ELSE 0 END) AS disabled',
                [$needsAttention, $needsAttention],
            )
            ->groupBy('workspace_id')
            ->get()
            ->mapWithKeys(fn (ConnectedAccount $row): array => [
                (string) $row->getAttribute('workspace_id') => [
                    'total' => (int) $row->getAttribute('total'),
                    'active' => (int) $row->getAttribute('active'),
                    'needs_attention' => (int) $row->getAttribute('needs_attention'),
                    'disabled' => (int) $row->getAttribute('disabled'),
                ],
            ])
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{scheduled: int, next_scheduled_at: string|null, next_7_days: int, problems: int, published_7_days: int}>
     */
    private static function postStats(array $ids, CarbonImmutable $now): array
    {
        $weekAhead = $now->addDays(self::WEEK_DAYS);
        $weekAgo = $now->subDays(self::WEEK_DAYS);
        $problemSince = $now->subDays(self::PROBLEM_WINDOW_DAYS);

        return Post::withoutGlobalScopes()
            ->whereIn('workspace_id', $ids)
            ->whereNull('deleted_at')
            ->selectRaw(
                'workspace_id,
                SUM(CASE WHEN status = ? AND scheduled_at >= ? THEN 1 ELSE 0 END) AS scheduled,
                MIN(CASE WHEN status = ? AND scheduled_at >= ? THEN scheduled_at END) AS next_scheduled_at,
                SUM(CASE WHEN status = ? AND scheduled_at >= ? AND scheduled_at < ? THEN 1 ELSE 0 END) AS next_7_days,
                SUM(CASE WHEN status IN (?, ?, ?) AND updated_at >= ? THEN 1 ELSE 0 END) AS problems,
                SUM(CASE WHEN status IN (?, ?) AND published_at >= ? THEN 1 ELSE 0 END) AS published_7_days',
                [
                    PostStatus::Scheduled->value, $now,
                    PostStatus::Scheduled->value, $now,
                    PostStatus::Scheduled->value, $now, $weekAhead,
                    PostStatus::Failed->value, PostStatus::Partial->value, PostStatus::Missed->value, $problemSince,
                    PostStatus::Published->value, PostStatus::Partial->value, $weekAgo,
                ],
            )
            ->groupBy('workspace_id')
            ->get()
            ->mapWithKeys(function (Post $row): array {
                $next = $row->getAttribute('next_scheduled_at');

                return [(string) $row->getAttribute('workspace_id') => [
                    'scheduled' => (int) $row->getAttribute('scheduled'),
                    'next_scheduled_at' => $next === null ? null : Date::parse($next, 'UTC')->toIso8601String(),
                    'next_7_days' => (int) $row->getAttribute('next_7_days'),
                    'problems' => (int) $row->getAttribute('problems'),
                    'published_7_days' => (int) $row->getAttribute('published_7_days'),
                ]];
            })
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private static function slotCounts(array $ids): array
    {
        return PostingSchedule::withoutGlobalScopes()
            ->whereIn('workspace_id', $ids)
            ->withCount('slots')
            ->get()
            ->mapWithKeys(fn (PostingSchedule $schedule): array => [
                (string) $schedule->workspace_id => (int) $schedule->getAttribute('slots_count'),
            ])
            ->all();
    }
}
