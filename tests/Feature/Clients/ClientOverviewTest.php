<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\ClientOverview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Inertia\Testing\AssertableInertia as Assert;

function clientFor(User $user, string $name): Workspace
{
    $workspace = Workspace::factory()->create(['name' => $name, 'owner_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);

    return $workspace;
}

function rowFor(array $rows, Workspace $workspace): array
{
    return collect($rows)->firstWhere('id', $workspace->id);
}

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC');
    $this->user = User::factory()->create();
});

test('lists only workspaces the user belongs to', function () {
    $mine = clientFor($this->user, 'Acme');
    $stranger = Workspace::factory()->create(['name' => 'Not mine']);
    ConnectedAccount::factory()->create(['workspace_id' => $stranger->id]);

    $rows = ClientOverview::make($this->user, $this->now);

    expect(collect($rows)->pluck('id')->all())->toBe([$mine->id]);
});

test('returns nothing for a user with no workspaces', function () {
    expect(ClientOverview::make($this->user, $this->now))->toBe([]);
});

test('is not narrowed by the request current workspace', function () {
    $a = clientFor($this->user, 'Alpha');
    $b = clientFor($this->user, 'Bravo');
    ConnectedAccount::factory()->create(['workspace_id' => $b->id]);
    Context::add('workspace_id', $a->id);

    $rows = ClientOverview::make($this->user, $this->now);

    expect(collect($rows)->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and(rowFor($rows, $b)['accounts']['total'])->toBe(1);
});

test('counts accounts by state and ignores disabled ones for health', function () {
    $client = clientFor($this->user, 'Acme');
    ConnectedAccount::factory()->count(2)->create(['workspace_id' => $client->id]);
    ConnectedAccount::factory()->create(['workspace_id' => $client->id, 'status' => ConnectedAccountStatus::NeedsAttention->value]);
    ConnectedAccount::factory()->create([
        'workspace_id' => $client->id,
        'status' => ConnectedAccountStatus::NeedsAttention->value,
        'disabled_at' => $this->now,
    ]);

    $row = rowFor(ClientOverview::make($this->user, $this->now), $client);

    expect($row['accounts'])->toBe(['total' => 4, 'active' => 2, 'needs_attention' => 1, 'disabled' => 1])
        ->and($row['health'])->toBe('attention');
});

test('summarises scheduled, upcoming, failed and published posts', function () {
    $client = clientFor($this->user, 'Acme');
    ConnectedAccount::factory()->create(['workspace_id' => $client->id]);

    $soon = $this->now->addDays(2);
    $later = $this->now->addDays(20);

    Post::factory()->create(['workspace_id' => $client->id, 'status' => PostStatus::Scheduled->value, 'scheduled_at' => $soon]);
    Post::factory()->create(['workspace_id' => $client->id, 'status' => PostStatus::Scheduled->value, 'scheduled_at' => $later]);
    // Past-due scheduled posts are not "upcoming".
    Post::factory()->create(['workspace_id' => $client->id, 'status' => PostStatus::Scheduled->value, 'scheduled_at' => $this->now->subDay()]);
    Post::factory()->create(['workspace_id' => $client->id, 'status' => PostStatus::Failed->value, 'updated_at' => $this->now->subDays(3)]);
    // A failure older than the window no longer counts.
    Post::factory()->create(['workspace_id' => $client->id, 'status' => PostStatus::Failed->value, 'updated_at' => $this->now->subDays(40)]);
    Post::factory()->create(['workspace_id' => $client->id, 'status' => PostStatus::Published->value, 'published_at' => $this->now->subDays(2)]);
    Post::factory()->create(['workspace_id' => $client->id, 'status' => PostStatus::Published->value, 'published_at' => $this->now->subDays(30)]);

    $row = rowFor(ClientOverview::make($this->user, $this->now), $client);

    expect($row['posts'])->toBe([
        'scheduled' => 2,
        'next_scheduled_at' => $soon->toIso8601String(),
        'next_7_days' => 1,
        'problems' => 1,
        'published_7_days' => 1,
    ])->and($row['health'])->toBe('attention');
});

test('flags a healthy client with nothing scheduled this week as empty', function () {
    $client = clientFor($this->user, 'Quiet');
    ConnectedAccount::factory()->create(['workspace_id' => $client->id]);

    expect(rowFor(ClientOverview::make($this->user, $this->now), $client)['health'])->toBe('empty');

    Post::factory()->create(['workspace_id' => $client->id, 'status' => PostStatus::Scheduled->value, 'scheduled_at' => $this->now->addDay()]);

    expect(rowFor(ClientOverview::make($this->user, $this->now), $client)['health'])->toBe('ok');
});

test('flags a client with no connected accounts', function () {
    $client = clientFor($this->user, 'New');

    expect(rowFor(ClientOverview::make($this->user, $this->now), $client)['health'])->toBe('no_accounts');
});

test('counts queue slots', function () {
    $client = clientFor($this->user, 'Acme');
    $schedule = PostingSchedule::factory()->create(['workspace_id' => $client->id]);
    PostingScheduleSlot::factory()->count(3)->create(['posting_schedule_id' => $schedule->id]);

    expect(rowFor(ClientOverview::make($this->user, $this->now), $client)['queue_slots'])->toBe(3);
});

test('sorts clients needing attention first, then alphabetically', function () {
    $ok = clientFor($this->user, 'Zed ok');
    ConnectedAccount::factory()->create(['workspace_id' => $ok->id]);
    Post::factory()->create(['workspace_id' => $ok->id, 'status' => PostStatus::Scheduled->value, 'scheduled_at' => $this->now->addDay()]);

    $broken = clientFor($this->user, 'Yak broken');
    ConnectedAccount::factory()->create(['workspace_id' => $broken->id, 'status' => ConnectedAccountStatus::NeedsAttention->value]);

    $empty = clientFor($this->user, 'Mid empty');
    ConnectedAccount::factory()->create(['workspace_id' => $empty->id]);

    $none = clientFor($this->user, 'Alpha none');

    $names = collect(ClientOverview::make($this->user, $this->now))->pluck('name')->all();

    expect($names)->toBe(['Yak broken', 'Mid empty', 'Alpha none', 'Zed ok']);
});

test('marks the current workspace', function () {
    $a = clientFor($this->user, 'Alpha');
    $b = clientFor($this->user, 'Bravo');
    $this->user->forceFill(['current_workspace_id' => $b->id])->save();

    $rows = ClientOverview::make($this->user->fresh(), $this->now);

    expect(rowFor($rows, $a)['is_current'])->toBeFalse()
        ->and(rowFor($rows, $b)['is_current'])->toBeTrue();
});

test('guests are redirected to login', function () {
    $this->get(route('clients.index'))->assertRedirect(route('login'));
});

test('the clients page renders the overview for the signed-in user', function () {
    $mine = clientFor($this->user, 'Acme');
    Workspace::factory()->create(['name' => 'Hidden']);

    $this->actingAs($this->user)->get(route('clients.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('clients/index')
            ->has('clients', 1)
            ->where('clients.0.id', $mine->id)
            ->where('clients.0.name', 'Acme'));
});
