<?php

namespace Tests\Feature\Feature\Meta;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Services\Meta\Instagram\InstagramConnectService;
use App\Services\Meta\MetaTokenVault;
use App\Support\Meta\MetaConnectionKind;
use App\Support\Meta\MetaOAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramChannelConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.app_id', 'test-meta-app-id');
        config()->set('meta.app_secret', 'test-meta-app-secret');
        config()->set('meta.graph_api_version', 'v20.0');
        config()->set(
            'meta.instagram_oauth_scopes',
            'instagram_business_basic,instagram_business_manage_messages'
        );
        config()->set('meta.instagram_oauth_redirect_uri', null);
    }

    public function test_authenticated_workspace_member_can_begin_instagram_oauth(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $response = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.channels.instagram.connect'));

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertIsString($target);
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $target);
        $this->assertStringNotContainsString('facebook.com', $target);
        $this->assertStringContainsString('client_id=test-meta-app-id', $target);
        $this->assertStringContainsString(
            'scope=instagram_business_basic%2Cinstagram_business_manage_messages',
            $target
        );
    }

    public function test_regular_member_cannot_begin_instagram_oauth(): void
    {
        [, $workspace] = $this->createWorkspaceOwner('company');
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.channels.instagram.connect'))
            ->assertForbidden();
    }

    public function test_guest_cannot_begin_instagram_oauth(): void
    {
        $this->get(route('workspace.channels.instagram.connect'))
            ->assertRedirect(route('login'));
    }

    public function test_callback_rejects_invalid_state(): void
    {
        [$owner] = $this->createWorkspaceOwner('company');

        Http::fake();

        $this->actingAs($owner)
            ->get(route('workspace.channels.instagram.callback', [
                'code' => 'dummy-code',
                'state' => 'not-a-valid-state',
            ]))
            ->assertRedirect(route('workspace.channels.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, WorkspaceMetaConnection::withoutGlobalScopes()->count());
    }

    public function test_callback_handles_oauth_error_from_meta(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $state = app(MetaOAuthState::class)->issue($workspace->id, $owner->id)['state'];

        Http::fake();

        $this->actingAs($owner)
            ->get(route('workspace.channels.instagram.callback', [
                'error' => 'access_denied',
                'error_description' => 'User denied access',
                'state' => $state,
            ]))
            ->assertRedirect(route('workspace.channels.index'))
            ->assertSessionHas('error', 'User denied access');

        $this->assertSame(0, WorkspaceMetaConnection::withoutGlobalScopes()->count());
    }

    public function test_callback_cannot_bind_connection_to_unintended_user(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $intruder = User::factory()->create();
        $state = app(MetaOAuthState::class)->issue($workspace->id, $owner->id)['state'];

        $this->fakeInstagramBusinessLoginSuccess();

        $this->actingAs($intruder)
            ->get(route('workspace.channels.instagram.callback', [
                'code' => 'auth-code-123',
                'state' => $state,
            ]))
            ->assertRedirect(route('workspace.channels.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, WorkspaceMetaConnection::withoutGlobalScopes()->count());
    }

    public function test_successful_callback_persists_encrypted_instagram_connection(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $state = app(MetaOAuthState::class)->issue($workspace->id, $owner->id)['state'];

        $this->fakeInstagramBusinessLoginSuccess();

        $this->actingAs($owner)
            ->get(route('workspace.channels.instagram.callback', [
                'code' => 'auth-code-123',
                'state' => $state,
            ]))
            ->assertRedirect(route('workspace.channels.index'))
            ->assertSessionHas('success');

        $connection = WorkspaceMetaConnection::withoutGlobalScopes()->first();
        $this->assertNotNull($connection);
        $this->assertSame($workspace->id, $connection->workspace_id);
        $this->assertSame(MetaConnectionKind::INSTAGRAM_USER, $connection->kind);
        $this->assertSame('17841400000000001', $connection->external_id);
        $this->assertSame('connected', $connection->status);
        $this->assertSame('@hasim.test', $connection->display_name);
        $this->assertNotNull($connection->token_expires_at);

        $this->assertSame(
            ['instagram_business_basic', 'instagram_business_manage_messages'],
            $connection->scopes
        );

        $this->assertSame('hasim.test', $connection->metadata['instagram_username'] ?? null);
        $this->assertSame('Business', $connection->metadata['account_type'] ?? null);
        $this->assertSame('instagram_business_login', $connection->metadata['login_product'] ?? null);
        $this->assertSame('graph.instagram.com', $connection->metadata['graph_host'] ?? null);
        $this->assertArrayNotHasKey('facebook_page_id', $connection->metadata ?? []);

        $this->assertNotNull($connection->access_token_ciphertext);
        $this->assertNotSame('long-lived-instagram-user-token', $connection->access_token_ciphertext);
        $this->assertSame('long-lived-instagram-user-token', $connection->accessToken());
        $this->assertSame(
            'long-lived-instagram-user-token',
            app(MetaTokenVault::class)->decrypt($connection->access_token_ciphertext)
        );

        $array = $connection->toArray();
        $this->assertArrayNotHasKey('access_token_ciphertext', $array);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.instagram.com/oauth/access_token';
        });
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://graph.instagram.com/access_token');
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.instagram.com/v20.0/me');
        });
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com');
        });
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'facebook.com');
        });
    }

    public function test_duplicate_connection_for_same_workspace_is_idempotent(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $state = app(MetaOAuthState::class)->issue($workspace->id, $owner->id)['state'];
        $this->fakeInstagramBusinessLoginSuccess();

        $this->actingAs($owner)->get(route('workspace.channels.instagram.callback', [
            'code' => 'auth-code-123',
            'state' => $state,
        ]));

        $stateTwo = app(MetaOAuthState::class)->issue($workspace->id, $owner->id)['state'];
        $this->fakeInstagramBusinessLoginSuccess();

        $this->actingAs($owner)->get(route('workspace.channels.instagram.callback', [
            'code' => 'auth-code-456',
            'state' => $stateTwo,
        ]));

        $this->assertSame(1, WorkspaceMetaConnection::withoutGlobalScopes()->count());
    }

    public function test_instagram_account_already_connected_to_other_workspace_is_rejected(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('company');

        $stateA = app(MetaOAuthState::class)->issue($workspaceA->id, $ownerA->id)['state'];
        $this->fakeInstagramBusinessLoginSuccess();
        $this->actingAs($ownerA)->get(route('workspace.channels.instagram.callback', [
            'code' => 'auth-code-a',
            'state' => $stateA,
        ]));

        $stateB = app(MetaOAuthState::class)->issue($workspaceB->id, $ownerB->id)['state'];
        $this->fakeInstagramBusinessLoginSuccess();

        $this->actingAs($ownerB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->get(route('workspace.channels.instagram.callback', [
                'code' => 'auth-code-b',
                'state' => $stateB,
            ]))
            ->assertRedirect(route('workspace.channels.index'))
            ->assertSessionHas('error');

        $this->assertSame(1, WorkspaceMetaConnection::withoutGlobalScopes()->count());
    }

    public function test_redirect_uri_matches_application_route(): void
    {
        $expected = route('workspace.channels.instagram.callback', absolute: true);

        $this->assertSame($expected, app(InstagramConnectService::class)->redirectUri());
    }

    private function fakeInstagramBusinessLoginSuccess(): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'access_token' => 'short-lived-instagram-user-token',
                'user_id' => '102078776356097',
                'permissions' => 'instagram_business_basic,instagram_business_manage_messages',
            ], 200),
            'graph.instagram.com/access_token*' => Http::response([
                'access_token' => 'long-lived-instagram-user-token',
                'token_type' => 'bearer',
                'expires_in' => 5184000,
            ], 200),
            'graph.instagram.com/v20.0/me*' => Http::response([
                'user_id' => '17841400000000001',
                'username' => 'hasim.test',
                'name' => 'Hasim Test',
                'account_type' => 'Business',
            ], 200),
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->enableWorkspaceFeature($workspace, 'whatsapp');

        return [$user, $workspace];
    }
}
