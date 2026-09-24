<?php

namespace Tests\Feature\Feature\Inbox;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Meta\Facebook\FacebookWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_message_accepts_json_without_redirect(): void
    {
        [$user, $workspace, $conversation] = $this->seedConversation('whatsapp');

        $this->actingAs($user);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->postJson(route('workspace.conversations.messages.store', $conversation), [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'مرحبًا من JSON',
            'metadata_json' => '{}',
        ]);

        $response->assertOk()
            ->assertJsonPath('message.content', 'مرحبًا من JSON')
            ->assertJsonPath('message.direction', 'outbound');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'مرحبًا من JSON',
        ]);
    }

    public function test_inbox_remembers_last_conversation_in_session(): void
    {
        [$user, $workspace, $conversation] = $this->seedConversation('facebook_messenger');

        $this->actingAs($user);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $customerName = Customer::withoutGlobalScopes()->findOrFail($conversation->customer_id)->name;

        $this->get(route('workspace.conversations.index', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee($customerName);

        $this->get(route('workspace.conversations.index'))
            ->assertOk()
            ->assertSee($customerName);
    }

    public function test_workspace_attachment_download_is_isolated_between_workspaces(): void
    {
        [$userA, $workspaceA, $conversationA] = $this->seedConversation('facebook_messenger');
        [$userB, $workspaceB] = $this->seedWorkspaceUser();

        Storage::fake('local');

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'conversation_id' => $conversationA->id,
            'customer_id' => $conversationA->customer_id,
            'direction' => 'inbound',
            'message_type' => 'image',
            'content' => null,
        ]);

        $path = "inbox/{$workspaceA->id}/{$message->id}/photo.jpg";
        Storage::disk('local')->put($path, 'binary-image');

        $attachment = MessageAttachment::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'message_id' => $message->id,
            'provider' => 'facebook_messenger',
            'provider_media_id' => 'media-1',
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12,
            'kind' => 'image',
            'status' => MessageAttachment::STATUS_STORED,
        ]);

        $this->actingAs($userA);
        $this->withSession(['current_workspace_id' => $workspaceA->id]);
        $this->get(route('workspace.message-attachments.download', $attachment))
            ->assertOk();

        $this->actingAs($userB);
        $this->withSession(['current_workspace_id' => $workspaceB->id]);
        $this->get(route('workspace.message-attachments.download', $attachment))
            ->assertNotFound();
    }

    public function test_human_handoff_disables_ai_for_same_conversation_only(): void
    {
        [$user, $workspace, $conversation] = $this->seedConversation('facebook_messenger');
        $other = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $conversation->customer_id,
            'channel' => 'whatsapp',
            'external_id' => 'wa:other',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        $this->inWorkspace($workspace, function () use ($conversation): void {
            $message = $conversation->messages()->create([
                'workspace_id' => $conversation->workspace_id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'حولني لشخص',
            ]);

            app(InboxHumanHandoffService::class)->maybeHandoffFromInboundMessage($conversation->fresh() ?? $conversation, $message);
        });

        $conversation->refresh();
        $other->refresh();

        $this->assertFalse($conversation->ai_enabled);
        $this->assertTrue($other->ai_enabled);
        $this->assertTrue($conversation->metadata['human_handoff'] ?? false);
    }

    public function test_facebook_inbound_handoff_prevents_ai_dispatch(): void
    {
        [$user, $workspace] = $this->seedWorkspaceUser();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Facebook User',
            'phone' => 'fb:123',
            'client_reference' => 'facebook:123',
        ]);

        Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'facebook_messenger',
            'external_id' => 'facebook:123',
            'status' => 'open',
            'ai_enabled' => true,
            'metadata' => [
                'facebook_user_id' => '123',
                'facebook_page_id' => 'page-1',
            ],
        ]);

        $message = $this->inWorkspace($workspace, function () use ($workspace) {
            return app(FacebookWebhookService::class)->storeIncomingMessage($workspace->id, [
                'sender_id' => '123',
                'recipient_id' => 'page-1',
                'message_id' => 'mid.handoff.1',
                'text' => 'بدي موظف',
                'message_type' => 'text',
                'attachments' => [],
                'metadata' => [],
            ]);
        });

        $conversation = Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertFalse($conversation->ai_enabled);
        $this->assertFalse(app(AiReplyEligibility::class)->mayGenerate($conversation, $message));
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Conversation}
     */
    private function seedConversation(string $channel): array
    {
        [$user, $workspace] = $this->seedWorkspaceUser();

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Customer',
            'phone' => '0500000000',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => $channel,
            'external_id' => $channel.':123',
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
        ]);

        return [$user, $workspace, $conversation];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function seedWorkspaceUser(): array
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
