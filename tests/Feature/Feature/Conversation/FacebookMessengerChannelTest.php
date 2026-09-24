<?php

namespace Tests\Feature\Feature\Conversation;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Conversation\ConversationService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacebookMessengerChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_can_be_created_with_facebook_messenger_channel(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user);

        $conversation = app(WorkspaceContext::class)->runForId($workspace->id, fn (): Conversation => app(ConversationService::class)->create([
            'workspace_id' => $workspace->id,
            'channel' => 'facebook_messenger',
            'external_id' => 'facebook:123456789',
            'status' => 'open',
            'ai_enabled' => true,
        ]));

        $this->assertSame('facebook_messenger', $conversation->channel);
        $this->assertSame('facebook_messenger', $conversation->metadata['channel_source'] ?? null);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'workspace_id' => $workspace->id,
            'channel' => 'facebook_messenger',
        ]);
    }

    public function test_store_conversation_request_accepts_facebook_messenger(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->post(route('workspace.conversations.store'), [
            'channel' => 'facebook_messenger',
            'external_id' => 'fb-user-99',
            'status' => 'open',
            'ai_enabled' => '1',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Conversation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('channel', 'facebook_messenger')
            ->count());
    }
}
