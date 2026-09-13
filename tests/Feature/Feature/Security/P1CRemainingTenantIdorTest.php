<?php

namespace Tests\Feature\Feature\Security;

use App\Jobs\Push\SendPushNotificationJob;
use App\Jobs\Stories\ExpireStoriesJob;
use App\Models\ApiIdempotencyKey;
use App\Models\AuthIdentity;
use App\Models\NotificationPreference;
use App\Models\Story;
use App\Models\StoryView;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\SocialAuthService;
use App\Services\Mobile\PushDeviceService;
use App\Services\Stories\StoryService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class P1CRemainingTenantIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_notification_preferences_are_per_user_and_workspace(): void
    {
        [$user, $workspaceA, $token] = $this->authMember('prefs-ab@example.com');
        $workspaceB = $this->attachWorkspace($user, 'Prefs B');

        $this->asWorkspace($token, $workspaceA)
            ->putJson('/api/mobile/v1/notification-preferences', [
                'messages' => false,
                'email' => true,
                'marketing' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.messages', false)
            ->assertJsonPath('data.marketing', true);

        $this->asWorkspace($token, $workspaceB)
            ->putJson('/api/mobile/v1/notification-preferences', [
                'messages' => true,
                'email' => false,
                'marketing' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.messages', true)
            ->assertJsonPath('data.email', false);

        $this->asWorkspace($token, $workspaceA)
            ->getJson('/api/mobile/v1/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.messages', false)
            ->assertJsonPath('data.marketing', true)
            ->assertJsonPath('data.email', true);

        $this->asWorkspace($token, $workspaceB)
            ->getJson('/api/mobile/v1/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.messages', true)
            ->assertJsonPath('data.email', false)
            ->assertJsonPath('data.marketing', false);

        [$other, $otherWorkspace, $otherToken] = $this->authMember('prefs-other@example.com');
        $this->asWorkspace($otherToken, $otherWorkspace)
            ->getJson('/api/mobile/v1/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.messages', true)
            ->assertJsonPath('data.marketing', false);

        $prefA = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceA->id)
            ->firstOrFail();

        $this->asWorkspace($otherToken, $otherWorkspace)
            ->putJson('/api/mobile/v1/notification-preferences/'.$prefA->id, [
                'messages' => true,
            ])
            ->assertNotFound();

        $this->assertFalse((bool) $prefA->fresh()->messages);
    }

    public function test_foreign_user_cannot_read_or_update_another_users_preferences(): void
    {
        [$userA, $workspaceA, $tokenA] = $this->authMember('pref-a@example.com');
        [$userB, $workspaceB, $tokenB] = $this->authMember('pref-b@example.com');

        $this->asWorkspace($tokenA, $workspaceA)
            ->putJson('/api/mobile/v1/notification-preferences', ['messages' => false])
            ->assertOk();

        $this->asWorkspace($tokenB, $workspaceB)
            ->getJson('/api/mobile/v1/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.messages', true);

        $this->asWorkspace($tokenB, $workspaceA)
            ->getJson('/api/mobile/v1/notification-preferences')
            ->assertForbidden();
    }

    public function test_idempotency_key_cannot_replay_across_workspaces_or_payloads(): void
    {
        [$user, $workspaceA, $token] = $this->authMember('idemp-ab@example.com');
        $workspaceB = $this->attachWorkspace($user, 'Idemp B');

        $first = $this->asWorkspace($token, $workspaceA)
            ->withHeader('Idempotency-Key', 'shared-key-k')
            ->postJson('/api/mobile/v1/stories', [
                'type' => 'text',
                'body_text' => 'Story in A only',
            ])
            ->assertCreated();

        $storyA = (int) $first->json('data.id');
        $this->assertSame('Story in A only', $first->json('data.body_text'));

        $replayA = $this->asWorkspace($token, $workspaceA)
            ->withHeader('Idempotency-Key', 'shared-key-k')
            ->postJson('/api/mobile/v1/stories', [
                'type' => 'text',
                'body_text' => 'Story in A only',
            ])
            ->assertCreated();
        $this->assertSame($storyA, (int) $replayA->json('data.id'));

        $this->asWorkspace($token, $workspaceA)
            ->withHeader('Idempotency-Key', 'shared-key-k')
            ->postJson('/api/mobile/v1/stories', [
                'type' => 'text',
                'body_text' => 'Different payload in A',
            ])
            ->assertStatus(409);

        $inB = $this->asWorkspace($token, $workspaceB)
            ->withHeader('Idempotency-Key', 'shared-key-k')
            ->postJson('/api/mobile/v1/stories', [
                'type' => 'text',
                'body_text' => 'Story in B only',
            ])
            ->assertCreated();

        $this->assertNotSame($storyA, (int) $inB->json('data.id'));
        $this->assertSame('Story in B only', $inB->json('data.body_text'));
        $this->assertStringNotContainsString('Story in A only', (string) $inB->getContent());

        [$other, $otherWorkspace, $otherToken] = $this->authMember('idemp-other@example.com');
        $otherCreate = $this->asWorkspace($otherToken, $otherWorkspace)
            ->withHeader('Idempotency-Key', 'shared-key-k')
            ->postJson('/api/mobile/v1/stories', [
                'type' => 'text',
                'body_text' => 'Other tenant story',
            ])
            ->assertCreated();
        $this->assertNotSame($storyA, (int) $otherCreate->json('data.id'));
        $this->assertStringNotContainsString('Story in A only', (string) $otherCreate->getContent());

        $record = ApiIdempotencyKey::query()->where('key', 'shared-key-k')->where('workspace_id', $workspaceA->id)->firstOrFail();
        $this->getJson('/api/mobile/v1/idempotency/'.$record->id)->assertNotFound();
        $this->asWorkspace($otherToken, $otherWorkspace)
            ->getJson('/api/mobile/v1/idempotency-keys/'.$record->id)
            ->assertNotFound();
    }

    public function test_story_view_is_parent_scoped_and_analytics_do_not_mix_tenants(): void
    {
        [$authorA, $workspaceA, $tokenA] = $this->authMember('story-a@example.com');
        [$authorB, $workspaceB, $tokenB] = $this->authMember('story-b@example.com');
        $viewer = User::factory()->create(['password' => 'password']);
        $workspaceA->users()->attach($viewer->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $workspaceB->users()->attach($viewer->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $viewerToken = $this->loginToken($viewer, $workspaceA);

        $storyA = $this->asWorkspace($tokenA, $workspaceA)
            ->postJson('/api/mobile/v1/stories', [
                'type' => 'text',
                'body_text' => 'Alpha story',
            ])
            ->assertCreated()
            ->json('data.id');

        $storyB = $this->asWorkspace($tokenB, $workspaceB)
            ->postJson('/api/mobile/v1/stories', [
                'type' => 'text',
                'body_text' => 'Bravo story',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->asWorkspace($viewerToken, $workspaceA)
            ->postJson('/api/mobile/v1/stories/'.$storyA.'/view')
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $viewerTokenB = $this->loginToken($viewer, $workspaceB);
        $this->asWorkspace($viewerTokenB, $workspaceB)
            ->postJson('/api/mobile/v1/stories/'.$storyB.'/view')
            ->assertOk();

        $this->asWorkspace($tokenA, $workspaceA)
            ->getJson('/api/mobile/v1/stories/'.$storyB)
            ->assertNotFound();
        $this->asWorkspace($tokenA, $workspaceA)
            ->postJson('/api/mobile/v1/stories/'.$storyB.'/view')
            ->assertNotFound();
        $this->asWorkspace($tokenA, $workspaceA)
            ->getJson('/api/mobile/v1/stories/'.$storyB.'/viewers')
            ->assertNotFound();

        $viewersA = $this->asWorkspace($tokenA, $workspaceA)
            ->getJson('/api/mobile/v1/stories/'.$storyA.'/viewers')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $viewersA);
        $this->assertSame($viewer->id, (int) $viewersA[0]['user_id']);

        $this->assertSame(1, (int) Story::withoutGlobalScopes()->find($storyA)->views_count);
        $this->assertSame(1, (int) Story::withoutGlobalScopes()->find($storyB)->views_count);

        $viewB = StoryView::query()->where('story_id', $storyB)->firstOrFail();
        $this->asWorkspace($tokenA, $workspaceA)
            ->getJson('/api/mobile/v1/story-views/'.$viewB->id)
            ->assertNotFound();
        $this->asWorkspace($tokenA, $workspaceA)
            ->deleteJson('/api/mobile/v1/story-views/'.$viewB->id)
            ->assertNotFound();

        $listA = collect($this->asWorkspace($tokenA, $workspaceA)->getJson('/api/mobile/v1/stories')->json('data'));
        $this->assertTrue($listA->contains(fn ($row) => (int) $row['id'] === (int) $storyA));
        $this->assertFalse($listA->contains(fn ($row) => (int) $row['id'] === (int) $storyB));
        $this->assertSame(1, (int) $listA->firstWhere('id', $storyA)['views_count']);
    }

    public function test_auth_identity_lookup_is_user_bound_and_not_directly_addressable(): void
    {
        $userA = User::factory()->create(['email' => 'ident-a@example.com', 'password' => 'password']);
        $userB = User::factory()->create(['email' => 'ident-b@example.com', 'password' => 'password']);
        $workspaceA = $this->ownWorkspace($userA, 'Ident A');
        $workspaceB = $this->ownWorkspace($userB, 'Ident B');

        AuthIdentity::query()->create([
            'user_id' => $userA->id,
            'provider' => 'google',
            'provider_user_id' => 'sub-user-a',
            'provider_email' => $userA->email,
        ]);
        AuthIdentity::query()->create([
            'user_id' => $userB->id,
            'provider' => 'google',
            'provider_user_id' => 'sub-user-b',
            'provider_email' => $userB->email,
        ]);

        $this->fakeGoogleUser($userA->email, $userA->name, 'sub-user-a');
        $payload = app(SocialAuthService::class)->loginWithAccessToken('google', 'ya29.google-token', $workspaceB->id);
        $this->assertSame($userA->id, (int) $payload['user']->id);
        $this->assertSame($workspaceA->id, (int) $payload['workspace']->id);

        $identityB = AuthIdentity::query()->where('provider_user_id', 'sub-user-b')->firstOrFail();
        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/auth/identities/'.$identityB->id)
            ->assertNotFound();
        $this->actingAs($userA, 'sanctum')
            ->deleteJson('/api/auth/identities/'.$identityB->id)
            ->assertNotFound();
        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/auth/identities', [
                'provider' => 'google',
                'provider_user_id' => 'sub-user-b',
            ])
            ->assertNotFound();

        $this->assertSame($userB->id, (int) $identityB->fresh()->user_id);
        $this->assertSame(1, AuthIdentity::query()->where('provider_user_id', 'sub-user-a')->count());
        $this->assertSame(1, AuthIdentity::query()->where('provider_user_id', 'sub-user-b')->count());
    }

    public function test_oauth_callback_does_not_rebind_an_already_authenticated_user(): void
    {
        $userA = User::factory()->create(['password' => 'password']);
        $this->ownWorkspace($userA, 'OAuth A');
        $before = AuthIdentity::query()->count();

        $this->actingAs($userA)
            ->get('/auth/google/callback?state=not-a-cashier-ticket')
            ->assertRedirect(route('workspace.choose'));

        $this->assertSame($before, AuthIdentity::query()->count());
        $this->assertSame($userA->id, auth()->id());
    }

    public function test_jobs_do_not_accept_forged_preference_or_story_view_ids(): void
    {
        [$userA, $workspaceA] = $this->makeMember('job-a@example.com');
        [$userB, $workspaceB] = $this->makeMember('job-b@example.com');

        app(PushDeviceService::class)->updatePreferences($userA, ['messages' => false], $workspaceA->id);
        app(PushDeviceService::class)->updatePreferences($userA, ['messages' => true], $workspaceB->id);

        $this->assertFalse(app(PushDeviceService::class)->shouldNotify($userA, $workspaceA->id, 'messages'));
        $this->assertTrue(app(PushDeviceService::class)->shouldNotify($userA, $workspaceB->id, 'messages'));

        $prefA = NotificationPreference::query()
            ->where('user_id', $userA->id)
            ->where('workspace_id', $workspaceA->id)
            ->firstOrFail();

        (new SendPushNotificationJob($userB->id, 'Hi', 'Body', [], 'messages', $workspaceA->id))
            ->handle(app(PushDeviceService::class));

        $this->assertFalse((bool) $prefA->fresh()->messages);
        $this->assertSame(0, StoryView::query()->count());

        $storyA = app(WorkspaceContext::class)->runFor($workspaceA, function () use ($workspaceA, $userA) {
            return app(StoryService::class)->create($workspaceA, $userA, [
                'type' => 'text',
                'body_text' => 'Expire me later',
            ]);
        });
        app(WorkspaceContext::class)->runFor($workspaceB, function () use ($userB, $storyA): void {
            StoryView::query()->firstOrCreate([
                'story_id' => $storyA->id,
                'user_id' => $userB->id,
            ], ['viewed_at' => now()]);
        });

        (new ExpireStoriesJob)->handle(app(StoryService::class));
        $this->assertSame(Story::STATUS_ACTIVE, $storyA->fresh()->status);
        $this->assertSame(1, StoryView::query()->where('story_id', $storyA->id)->count());
    }

    /**
     * @return array{0:User,1:Workspace,2:string}
     */
    private function authMember(string $email): array
    {
        [$user, $workspace] = $this->makeMember($email);

        return [$user, $workspace, $this->loginToken($user, $workspace)];
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

        return [$user, $this->ownWorkspace($user, $email)];
    }

    private function ownWorkspace(User $user, string $name): Workspace
    {
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $workspace;
    }

    private function attachWorkspace(User $user, string $name): Workspace
    {
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $workspace;
    }

    private function loginToken(User $user, Workspace $workspace): string
    {
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        return (string) $login->json('data.token');
    }

    private function asWorkspace(string $token, Workspace $workspace)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->withHeader('X-Workspace-Id', (string) $workspace->id);
    }

    private function fakeGoogleUser(string $email, string $name, string $googleId): void
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn($googleId);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn($name);
        $socialUser->shouldReceive('getNickname')->andReturnNull();
        $socialUser->shouldReceive('getAvatar')->andReturnNull();

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('userFromToken')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }
}
