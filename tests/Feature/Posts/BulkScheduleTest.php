<?php

use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Posts\MediaStorageService;
use Inertia\Testing\AssertableInertia as Assert;

function bulkMember(string $timezone = 'UTC', WorkspaceRole $role = WorkspaceRole::Owner): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'timezone' => $timezone]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    return [$user, $workspace];
}

function bulkCsv(string ...$lines): string
{
    return implode("\n", ['account,date,time,text,image_url,format', ...$lines]);
}

test('renders the bulk page with the template', function () {
    bulkMember();

    test()->get('/posts/bulk')->assertInertia(fn (Assert $page) => $page
        ->component('posts/bulk')
        ->has('template')
        ->where('maxRows', 50));
});

test('preview flags good rows and explains bad ones', function () {
    [, $workspace] = bulkMember();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'handle' => '@brand']);

    $response = test()->postJson('/posts/bulk/preview', ['csv' => bulkCsv(
        'brand,2030-01-01,09:00,Hello,,post',
        'nobody,2030-01-01,09:00,Hello,,',
        'brand,01/02/2030,09:00,Hello,,',
        'brand,2020-01-01,09:00,Hello,,',
        'brand,2030-01-01,09:00,Reel time,,reel',
        'brand,2030-01-01,10:00,,,',
    )])->assertOk();

    $rows = $response->json('rows');
    expect($rows[0]['errors'])->toBe([]);
    expect($rows[0]['scheduled_at'])->toBe('2030-01-01T09:00:00+00:00');
    expect($rows[1]['errors'][0])->toContain('No connected account');
    expect($rows[2]['errors'][0])->toContain('YYYY-MM-DD');
    expect($rows[3]['errors'][0])->toContain('already passed');
    expect($rows[4]['errors'][0])->toContain('Reels');
    expect($rows[5]['errors'][0])->toContain('text or an image');
    expect(Post::query()->count())->toBe(0);
});

test('times are read in the workspace timezone', function () {
    [, $workspace] = bulkMember('Asia/Kolkata');
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'handle' => '@brand']);

    $rows = test()->postJson('/posts/bulk/preview', ['csv' => bulkCsv('@brand,2030-01-01,09:00,Hi,,')])->json('rows');

    expect($rows[0]['scheduled_at'])->toBe('2030-01-01T03:30:00+00:00');
});

test('accounts are matched only inside the current workspace', function () {
    bulkMember();
    ConnectedAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'handle' => '@other']);

    $rows = test()->postJson('/posts/bulk/preview', ['csv' => bulkCsv('@other,2030-01-01,09:00,Hi,,')])->json('rows');

    expect($rows[0]['errors'][0])->toContain('No connected account');
});

test('an ambiguous handle needs a platform prefix', function () {
    [, $workspace] = bulkMember();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'handle' => '@brand']);
    ConnectedAccount::factory()->bluesky()->create(['workspace_id' => $workspace->id, 'handle' => '@brand']);

    $rows = test()->postJson('/posts/bulk/preview', ['csv' => bulkCsv(
        '@brand,2030-01-01,09:00,Hi,,',
        'bluesky:@brand,2030-01-01,09:00,Hi,,',
    )])->json('rows');

    expect($rows[0]['errors'][0])->toContain('platform:handle');
    expect($rows[1]['errors'])->toBe([]);
});

test('accepts tab separated input and quoted multi-line text', function () {
    [, $workspace] = bulkMember();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'handle' => '@brand']);

    $tsv = "account\tdate\ttime\ttext\n@brand\t2030-01-01\t09:00\t\"Line one\nLine two\"";
    $rows = test()->postJson('/posts/bulk/preview', ['csv' => $tsv])->json('rows');

    expect($rows[0]['errors'])->toBe([]);
    expect($rows[0]['text'])->toBe("Line one\nLine two");
});

test('rejects a missing header and oversize files', function () {
    bulkMember();

    test()->postJson('/posts/bulk/preview', ['csv' => "foo,bar\n1,2"])
        ->assertOk()->assertJsonPath('error', fn ($e) => str_contains($e, 'header'));

    $many = array_fill(0, 51, 'x,2030-01-01,09:00,Hi,,');
    test()->postJson('/posts/bulk/preview', ['csv' => bulkCsv(...$many)])
        ->assertJsonPath('error', fn ($e) => str_contains($e, 'at most 50'));
});

test('commit schedules valid rows and reports the rest', function () {
    [, $workspace] = bulkMember();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'handle' => '@brand']);

    $response = test()->postJson('/posts/bulk', ['csv' => bulkCsv(
        '@brand,2030-01-01,09:00,First,,',
        'nobody,2030-01-01,09:00,Nope,,',
        '@brand,2030-01-02,09:00,Second,,',
    )])->assertOk();

    expect($response->json('scheduled'))->toHaveCount(2);
    expect($response->json('failed.0.line'))->toBe(3);

    $posts = Post::query()->where('status', PostStatus::Scheduled)->orderBy('scheduled_at')->get();
    expect($posts)->toHaveCount(2);
    expect($posts[0]->base_text)->toBe('First');
    expect($posts[0]->scheduled_at->toIso8601String())->toBe('2030-01-01T09:00:00+00:00');
    expect($posts[0]->targets()->pluck('connected_account_id')->all())->toBe([$account->id]);
});

test('committing the same file twice does not duplicate posts', function () {
    [, $workspace] = bulkMember();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'handle' => '@brand']);
    $csv = bulkCsv('@brand,2030-01-01,09:00,Once,,');

    test()->postJson('/posts/bulk', ['csv' => $csv])->assertOk();
    $second = test()->postJson('/posts/bulk', ['csv' => $csv])->assertOk();

    expect($second->json('scheduled'))->toHaveCount(0);
    expect($second->json('failed.0.message'))->toContain('Duplicate');
    expect(Post::query()->where('status', PostStatus::Scheduled)->count())->toBe(1);
});

test('rows that repeat inside one file are skipped', function () {
    [, $workspace] = bulkMember();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'handle' => '@brand']);

    $rows = test()->postJson('/posts/bulk/preview', ['csv' => bulkCsv(
        '@brand,2030-01-01,09:00,Same,,',
        '@brand,2030-01-01,09:00,Same,,',
    )])->json('rows');

    expect($rows[0]['errors'])->toBe([]);
    expect($rows[1]['errors'][0])->toContain('Duplicate');
});

test('image rows attach downloaded media and stories drop their caption', function () {
    [, $workspace] = bulkMember();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'handle' => '@brand']);

    $this->mock(MediaStorageService::class, function ($mock) use ($workspace): void {
        $mock->shouldReceive('storeFromUrl')->twice()->andReturnUsing(fn () => PostMedia::factory()->create([
            'workspace_id' => $workspace->id, 'post_id' => null,
        ]));
    });

    $response = test()->postJson('/posts/bulk', ['csv' => bulkCsv(
        '@brand,2030-01-01,09:00,With pic,https://example.com/a.jpg,post',
        '@brand,2030-01-02,09:00,ignored,https://example.com/b.jpg,story',
    )])->assertOk();

    expect($response->json('scheduled'))->toHaveCount(2);
    $post = Post::query()->where('base_text', 'With pic')->firstOrFail();
    expect($post->media()->count())->toBe(1);
    $story = Post::query()->whereDate('scheduled_at', '2030-01-02')->firstOrFail();
    expect($story->base_text)->toBe('');
    expect($story->targets->first()->format->value)->toBe('story');
    expect($account->id)->toBe($story->targets->first()->connected_account_id);
});

test('a row that fails after the draft exists leaves no scheduled post behind', function () {
    [, $workspace] = bulkMember();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'handle' => '@brand']);

    $this->mock(MediaStorageService::class, function ($mock): void {
        $mock->shouldReceive('storeFromUrl')->andThrow(new RuntimeException('Image could not be downloaded.'));
    });

    $response = test()->postJson('/posts/bulk', ['csv' => bulkCsv('@brand,2030-01-01,09:00,Hi,https://example.com/a.jpg,')])->assertOk();

    expect($response->json('failed.0.message'))->toContain('could not be downloaded');
    expect(Post::query()->where('status', PostStatus::Scheduled)->count())->toBe(0);
});

test('guests are redirected', function () {
    test()->get('/posts/bulk')->assertRedirect();
    test()->postJson('/posts/bulk', ['csv' => 'x'])->assertUnauthorized();
});
