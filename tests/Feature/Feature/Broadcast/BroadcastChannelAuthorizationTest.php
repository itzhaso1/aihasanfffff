<?php

namespace Tests\Feature\Feature\Broadcast;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
    }

    public function test_workspace_conversations_channel_auth_denies_non_member(): void
    {
        [, $workspace] = $this->ownerAndWorkspace();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => 'private-workspace.'.$workspace->id.'.conversations',
            ])
            ->assertForbidden();
    }

    public function test_workspace_member_passes_conversations_channel_authorization(): void
    {
        [$owner, $workspace] = $this->ownerAndWorkspace();

        $allowed = Workspace::withoutGlobalScopes()
            ->whereKey($workspace->id)
            ->whereHas('users', function ($query) use ($owner): void {
                $query->where('users.id', $owner->id)->where('workspace_users.status', 'active');
            })
            ->exists();

        $this->assertTrue($allowed);
    }

    public function test_conversation_channel_auth_denies_foreign_conversation(): void
    {
        [$ownerA, $workspaceA] = $this->ownerAndWorkspace();
        [, $workspaceB] = $this->ownerAndWorkspace();

        $conversationB = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'channel' => 'manual',
            'external_id' => 'ext-b',
            'status' => 'open',
        ]);

        $this->actingAs($ownerA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => 'private-workspace.'.$workspaceA->id.'.conversation.'.$conversationB->id,
            ])
            ->assertForbidden();
    }

    public function test_pos_broadcast_channel_is_not_authorized_for_workspace_members(): void
    {
        [$owner, $workspace] = $this->ownerAndWorkspace();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => 'private-workspace.'.$workspace->id.'.pos',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function ownerAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
