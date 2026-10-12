<?php

namespace Tests\Feature\Mobile;

use App\Models\Story;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Stories\StoryService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileStoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_story_create_text_list_view_and_expire_hides(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('stories@example.com');

        $create = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/stories', [
                'type' => 'text',
                'body_text' => 'مرحباً بالجميع',
                'background_color' => '#06C2A4',
                'visibility' => 'workspace',
                'expires_in_hours' => 24,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.body_text', 'مرحباً بالجميع')
            ->assertJsonPath('data.status', 'active');

        $storyId = $create->json('data.id');

        $list = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/stories')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($list->json('data'))->pluck('id')->all();
        $this->assertContains($storyId, $ids);

        $viewer = User::factory()->create(['password' => 'password']);
        $workspace->users()->attach($viewer->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $viewerLogin = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $viewer->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();
        $this->assertSame($viewer->id, (int) $viewerLogin->json('data.user.id'));
        $viewerToken = $viewerLogin->json('data.token');

        // Reset auth guard cache so bearer token for a different user is honored.
        $this->app['auth']->forgetGuards();

        $beforeView = $this->withToken($viewerToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/stories')
            ->assertOk();
        $viewerRow = collect($beforeView->json('data'))->firstWhere(fn ($row) => (int) $row['id'] === (int) $storyId);
        $this->assertFalse((bool) ($viewerRow['viewed_by_me'] ?? true));

        $this->withToken($viewerToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/stories/{$storyId}/view")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_mine', false)
            ->assertJsonPath('data.viewed_by_me', true);

        $this->assertDatabaseHas('story_views', [
            'story_id' => $storyId,
            'user_id' => $viewer->id,
        ]);
        $this->assertSame(1, (int) Story::withoutGlobalScopes()->find($storyId)->views_count);

        // Idempotent second view should not increment views_count again.
        $this->app['auth']->forgetGuards();
        $this->withToken($viewerToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/stories/{$storyId}/view")
            ->assertOk();
        $this->assertSame(1, (int) Story::withoutGlobalScopes()->find($storyId)->views_count);

        // Expire and ensure list hides it
        Story::withoutGlobalScopes()->whereKey($storyId)->update([
            'expires_at' => now()->subMinute(),
        ]);
        app(StoryService::class)->expireOldStories($workspace);

        $afterExpire = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/stories')
            ->assertOk();

        $idsAfter = collect($afterExpire->json('data'))->pluck('id')->all();
        $this->assertNotContains($storyId, $idsAfter);
        $this->assertSame(Story::STATUS_EXPIRED, Story::withoutGlobalScopes()->find($storyId)->status);
    }

    /**
     * @return array{0:User,1:Workspace,2:string}
     */
    private function authMember(string $email): array
    {
        [$user, $workspace] = $this->makeMember($email);
        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        return [$user, $workspace, $login->json('data.token')];
    }

    /**
     * @return array{0:User,1:Workspace}
     */
    private function makeMember(string $email): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => 'password',
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
