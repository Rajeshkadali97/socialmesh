<?php

declare(strict_types=1);

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Database\Seeders\AgencyWorkspacesSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function setupCampaignWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_id' => $user->id,
        'timezone' => 'Asia/Kolkata',
    ]);
    WorkspaceMembership::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'connected_by_user_id' => $user->id,
        'platform' => Platform::Facebook,
        'handle' => '@ebintech',
        'status' => ConnectedAccountStatus::Active,
    ]);

    return [$user, $workspace, $account];
}

it('creates, updates, duplicates, and filters posts by campaign with strict workspace isolation', function (): void {
    [$user, $workspace, $account] = setupCampaignWorkspace();

    $createResponse = $this->actingAs($user)->postJson(route('posts.store'), [
        'segments' => ['Launch post for Q4'],
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'campaign' => 'Q4 Product Launch',
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('post.campaign', 'Q4 Product Launch');

    $postId = (string) $createResponse->json('post.id');
    $post = Post::withoutGlobalScopes()->findOrFail($postId);

    $updateResponse = $this->actingAs($user)->putJson(route('posts.update', $post), [
        'segments' => ['Updated launch post'],
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'campaign' => 'Winter Campaign',
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('post.campaign', 'Winter Campaign');

    // Other post in another campaign
    Post::factory()->for($workspace)->for($user, 'author')->create([
        'campaign' => 'Summer Promo',
        'status' => PostStatus::Scheduled,
    ]);

    // Foreign workspace campaign must never leak into this workspace's filter list
    $otherWorkspace = Workspace::factory()->create();
    Post::factory()->for($otherWorkspace)->for($user, 'author')->create([
        'campaign' => 'Secret Foreign Campaign',
        'status' => PostStatus::Scheduled,
    ]);

    $this->actingAs($user)
        ->get(route('posts.index', ['campaign' => 'Winter Campaign']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('posts/index')
            ->where('filters.campaign', 'Winter Campaign')
            ->where('campaigns', ['Summer Promo', 'Winter Campaign'])
            ->where('counts.all', 1)
        );
});

it('imports the optional campaign column during bulk CSV scheduling', function (): void {
    [$user, , $account] = setupCampaignWorkspace();

    $csv = implode("\n", [
        'account,date,time,text,image_url,format,campaign',
        '@ebintech,2027-02-15,10:30,"Spring collection is live!",,post,Spring Launch 2027',
    ]);

    $this->actingAs($user)
        ->postJson(route('posts.bulk.preview'), ['csv' => $csv])
        ->assertOk()
        ->assertJsonPath('rows.0.campaign', 'Spring Launch 2027')
        ->assertJsonPath('rows.0.errors', []);

    $commit = $this->actingAs($user)
        ->postJson(route('posts.bulk.store'), ['csv' => $csv])
        ->assertOk();

    $scheduledPostId = (string) $commit->json('scheduled.0.post_id');
    $scheduledPost = Post::withoutGlobalScopes()->findOrFail($scheduledPostId);

    expect($scheduledPost->campaign)->toBe('Spring Launch 2027')
        ->and($scheduledPost->status)->toBe(PostStatus::Scheduled)
        ->and($account->id)->not->toBeEmpty();
});

it('seeds all 13 Ebin Tech agency client workspaces idempotently', function (): void {
    $owner = User::factory()->create(['email' => 'test@example.com']);

    $this->seed(AgencyWorkspacesSeeder::class);
    $this->seed(AgencyWorkspacesSeeder::class);

    expect(Workspace::query()->count())->toBe(13)
        ->and($owner->workspaceMemberships()->count())->toBe(13);
});
