<?php

namespace Tests\Feature\Feature\Meta;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacebookChannelConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.app_id', 'test-meta-app-id');
        config()->set('meta.app_secret', 'test-meta-app-secret');
        config()->set('meta.graph_api_version', 'v20.0');
        config()->set(
            'meta.facebook_oauth_scopes',
            'pages_messaging,pages_show_list,pages_read_engagement',
        );
        config()->set('meta.facebook_oauth_redirect_uri', null);
    }

    public function test_owner_facebook_connect_redirects_away_to_facebook_dialog(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $response = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.channels.facebook.connect'));

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertIsString($target);
        $this->assertStringStartsWith('https://www.facebook.com/v20.0/dialog/oauth?', $target);
        $this->assertStringContainsString('client_id=test-meta-app-id', $target);
        $this->assertStringNotContainsString('test-meta-app-secret', $target);
    }

    public function test_guest_cannot_begin_facebook_oauth(): void
    {
        $this->get(route('workspace.channels.facebook.connect'))
            ->assertRedirect(route('login'));
    }

    public function test_regular_member_cannot_begin_facebook_oauth(): void
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
            ->get(route('workspace.channels.facebook.connect'))
            ->assertForbidden();
    }

    public function test_callback_rejects_invalid_state(): void
    {
        [$owner] = $this->createWorkspaceOwner('company');

        $this->actingAs($owner)
            ->get(route('workspace.channels.facebook.callback', [
                'code' => 'fb-code',
                'state' => 'invalid-state',
            ]))
            ->assertRedirect(route('workspace.channels.index'))
            ->assertSessionHas('error');
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
