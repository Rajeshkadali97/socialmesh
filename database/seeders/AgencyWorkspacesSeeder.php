<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Seeder;

/**
 * Seeds Ebin Tech's 13 client workspaces with realistic timezones, connected
 * social accounts, posting schedules, and sample campaign posts for local/dev use.
 */
class AgencyWorkspacesSeeder extends Seeder
{
    /**
     * @return list<array{slug: string, name: string, timezone: string, handle: string, campaign: string, platforms: list<Platform>}>
     */
    public static function clientDefinitions(): array
    {
        return [
            [
                'slug' => 'ebin-tech',
                'name' => 'Ebin Tech',
                'timezone' => 'Asia/Kolkata',
                'handle' => 'ebintech',
                'campaign' => 'Agency Brand Q4',
                'platforms' => [Platform::Instagram, Platform::Facebook, Platform::Threads, Platform::LinkedIn],
            ],
            [
                'slug' => 'rk-enterprises',
                'name' => 'RK Enterprises',
                'timezone' => 'Asia/Kolkata',
                'handle' => 'rkenterprises',
                'campaign' => 'Industrial Catalog',
                'platforms' => [Platform::Facebook, Platform::Instagram, Platform::LinkedIn],
            ],
            [
                'slug' => 'aurora-aesthetics',
                'name' => 'Aurora Aesthetics',
                'timezone' => 'Asia/Kolkata',
                'handle' => 'auroraaesthetics',
                'campaign' => 'Festive Glow',
                'platforms' => [Platform::Instagram, Platform::Facebook, Platform::Threads],
            ],
            [
                'slug' => 'spice-route-bistro',
                'name' => 'Spice Route Bistro',
                'timezone' => 'Asia/Kolkata',
                'handle' => 'spiceroutebistro',
                'campaign' => 'Weekend Tasting Menu',
                'platforms' => [Platform::Instagram, Platform::Facebook],
            ],
            [
                'slug' => 'verde-interiors',
                'name' => 'Verde Living & Interiors',
                'timezone' => 'Asia/Kolkata',
                'handle' => 'verdeinteriors',
                'campaign' => 'Studio Showcase',
                'platforms' => [Platform::Instagram, Platform::Facebook, Platform::Threads],
            ],
            [
                'slug' => 'nexus-fitness',
                'name' => 'Nexus Fitness Club',
                'timezone' => 'Asia/Kolkata',
                'handle' => 'nexusfitness',
                'campaign' => 'New Year Transformation',
                'platforms' => [Platform::Instagram, Platform::Threads],
            ],
            [
                'slug' => 'kerala-craft-co',
                'name' => 'Kerala Craft Collective',
                'timezone' => 'Asia/Kolkata',
                'handle' => 'keralacraftco',
                'campaign' => 'Handloom Heritage',
                'platforms' => [Platform::Instagram, Platform::Facebook],
            ],
            [
                'slug' => 'skyline-realty',
                'name' => 'Skyline Realty Group',
                'timezone' => 'Asia/Dubai',
                'handle' => 'skylinerealty',
                'campaign' => 'Waterfront Residences',
                'platforms' => [Platform::Instagram, Platform::Facebook, Platform::LinkedIn],
            ],
            [
                'slug' => 'lumina-dental',
                'name' => 'Lumina Dental Studio',
                'timezone' => 'Asia/Kolkata',
                'handle' => 'luminadental',
                'campaign' => 'Smile Care Awareness',
                'platforms' => [Platform::Instagram, Platform::Facebook],
            ],
            [
                'slug' => 'cloudnine-roasters',
                'name' => 'CloudNine Coffee Roasters',
                'timezone' => 'Asia/Kolkata',
                'handle' => 'cloudnineroasters',
                'campaign' => 'Single Origin Drop',
                'platforms' => [Platform::Instagram, Platform::Threads],
            ],
            [
                'slug' => 'veloce-motors',
                'name' => 'Veloce Detailing & Motors',
                'timezone' => 'Asia/Kolkata',
                'handle' => 'velocemotors',
                'campaign' => 'Ceramic Pro Launch',
                'platforms' => [Platform::Instagram, Platform::Facebook],
            ],
            [
                'slug' => 'zenith-edtech',
                'name' => 'Zenith Academy',
                'timezone' => 'Asia/Kolkata',
                'handle' => 'zenithacademy',
                'campaign' => 'Spring Cohort Admissions',
                'platforms' => [Platform::Instagram, Platform::Facebook, Platform::LinkedIn],
            ],
            [
                'slug' => 'harbor-hospitality',
                'name' => 'Harbor Boutique Stays',
                'timezone' => 'Europe/London',
                'handle' => 'harborstays',
                'campaign' => 'Coastal Escape',
                'platforms' => [Platform::Instagram, Platform::Facebook, Platform::Threads],
            ],
        ];
    }

    public function run(): void
    {
        $owner = User::query()->where('email', 'test@example.com')->first()
            ?? User::query()->first();

        if (! $owner instanceof User) {
            return;
        }

        foreach (self::clientDefinitions() as $index => $def) {
            $workspace = Workspace::query()->firstOrCreate(
                ['slug' => $def['slug']],
                [
                    'name' => $def['name'],
                    'owner_id' => $owner->id,
                    'timezone' => $def['timezone'],
                ],
            );

            WorkspaceMembership::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'user_id' => $owner->id,
                ],
                ['role' => WorkspaceRole::Owner],
            );

            $schedule = PostingSchedule::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $workspace->id],
            );

            if ($schedule->slots()->count() === 0) {
                PostingScheduleSlot::create([
                    'posting_schedule_id' => $schedule->id,
                    'weekday' => 1,
                    'hour' => 10,
                    'minute' => 0,
                    'position' => 0,
                ]);
                PostingScheduleSlot::create([
                    'posting_schedule_id' => $schedule->id,
                    'weekday' => 4,
                    'hour' => 16,
                    'minute' => 30,
                    'position' => 1,
                ]);
            }

            $primaryAccount = null;
            foreach ($def['platforms'] as $platform) {
                $account = ConnectedAccount::withoutGlobalScopes()->firstOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'platform' => $platform->value,
                        'remote_account_id' => $def['slug'].'-'.$platform->value,
                    ],
                    [
                        'handle' => '@'.$def['handle'],
                        'display_name' => $def['name'],
                        'auth_method' => 'oauth',
                        'connected_by_user_id' => $owner->id,
                        'token_expires_at' => now()->addDays(45),
                        'status' => ConnectedAccountStatus::Active,
                    ],
                );
                $primaryAccount ??= $account;
            }

            if ($primaryAccount instanceof ConnectedAccount && ! Post::withoutGlobalScopes()->where('workspace_id', $workspace->id)->exists()) {
                $scheduledPost = Post::withoutGlobalScopes()->create([
                    'workspace_id' => $workspace->id,
                    'author_id' => $owner->id,
                    'campaign' => $def['campaign'],
                    'base_text' => "{$def['name']} — {$def['campaign']} update scheduled via SocialMesh.",
                    'segments' => ["{$def['name']} — {$def['campaign']} update scheduled via SocialMesh."],
                    'status' => PostStatus::Scheduled,
                    'scheduled_at' => now()->addHours(($index + 1) * 6),
                ]);

                PostTarget::create([
                    'post_id' => $scheduledPost->id,
                    'connected_account_id' => $primaryAccount->id,
                    'platform' => $primaryAccount->platform->value,
                    'sections' => [$scheduledPost->base_text],
                ]);
            }
        }
    }
}
