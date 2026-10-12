<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostShare;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

function reviewShare(string $token, PostStatus $status = PostStatus::Scheduled): PostShare
{
    $workspace = Workspace::factory()->create();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => User::factory()->create()->id,
        'status' => $status,
        'scheduled_at' => $status === PostStatus::Scheduled ? now()->addDay() : null,
    ]);

    return PostShare::factory()->for($post)->state(['token_hash' => hash('sha256', $token)])->create();
}

it('lets a client approve a scheduled post without logging in', function (): void {
    $share = reviewShare('ok-token');

    $this->postJson('/share/ok-token/decision', ['decision' => 'approved', 'reviewer_name' => ' Priya '])
        ->assertOk()
        ->assertJsonPath('decision', 'approved')
        ->assertJsonPath('reviewer_name', 'Priya');

    $share->refresh();
    expect($share->decision)->toBe('approved')->and($share->decided_at)->not->toBeNull();
});

it('requires a note when changes are requested and stores it', function (): void {
    $share = reviewShare('chg-token');

    $this->postJson('/share/chg-token/decision', ['decision' => 'changes_requested'])
        ->assertStatus(422)->assertJsonValidationErrors('note');

    $this->postJson('/share/chg-token/decision', ['decision' => 'changes_requested', 'note' => 'Swap the photo'])->assertOk();
    expect($share->refresh()->decision_note)->toBe('Swap the photo');
});

it('lets a later approval replace earlier change requests and clears the note', function (): void {
    $share = reviewShare('flip-token');

    $this->postJson('/share/flip-token/decision', ['decision' => 'changes_requested', 'note' => 'Fix typo'])->assertOk();
    $this->postJson('/share/flip-token/decision', ['decision' => 'approved'])->assertOk();

    $share->refresh();
    expect($share->decision)->toBe('approved')->and($share->decision_note)->toBeNull();
});

it('rejects unknown decisions, dead links and posts already published', function (): void {
    reviewShare('bad-token');
    $this->postJson('/share/bad-token/decision', ['decision' => 'maybe'])->assertStatus(422);

    $this->postJson('/share/nope/decision', ['decision' => 'approved'])->assertNotFound();

    reviewShare('revoked-token')->forceFill(['revoked_at' => now()])->save();
    $this->postJson('/share/revoked-token/decision', ['decision' => 'approved'])->assertNotFound();

    reviewShare('pub-token', PostStatus::Published);
    $this->postJson('/share/pub-token/decision', ['decision' => 'approved'])->assertStatus(422);
});

it('exposes the current verdict to the share page', function (): void {
    reviewShare('page-token')->forceFill(['decision' => 'approved', 'decided_at' => now()])->save();

    $this->get('/share/page-token')->assertInertia(fn ($page) => $page
        ->where('review.can_review', true)
        ->where('review.decision', 'approved'));
});

it('shows staff each link’s verdict', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    PostShare::factory()->for($post)->create(['decision' => 'changes_requested', 'decision_note' => 'Shorter please']);

    $this->actingAs($user)->getJson(route('posts.shares.index', $post))
        ->assertOk()
        ->assertJsonPath('0.decision', 'changes_requested')
        ->assertJsonPath('0.decision_note', 'Shorter please');
});
