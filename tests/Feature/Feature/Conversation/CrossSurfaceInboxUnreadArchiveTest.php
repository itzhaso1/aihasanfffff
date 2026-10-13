<?php

namespace Tests\Feature\Feature\Conversation;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Mobile\ConversationInboxService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossSurfaceInboxUnreadArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_mark_read_is_per_user_and_does_not_clear_teammate_unread(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->makeMember('owner-unread@example.com', 'owner');
        [$teammate] = $this->makeMember('teammate-unread@example.com', 'agent', $workspace);

        $conversation = $this->makeOpenConversation($workspace);
        $inbound = $this->makeInboundMessage($conversation);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index', ['conversation' => $conversation->id]))
            ->assertOk();

        $inbox = app(ConversationInboxService::class);
        $this->inWorkspace($workspace, function () use ($inbox, $conversation, $owner, $teammate): void {
            $this->assertSame(0, $inbox->unreadCountForConversation($conversation->fresh(), $owner));
            $this->assertSame(1, $inbox->unreadCountForConversation($conversation->fresh(), $teammate));
        });

        $ownerToken = $this->loginToken($owner, $workspace);

        $this->withToken($ownerToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson("/api/mobile/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame($inbound->id, (int) Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('conversation_id', $conversation->id)
            ->max('id'));
    }

    public function test_mobile_mark_read_is_visible_to_web_inbox_for_same_user(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace] = $this->makeMember('mobile-read@example.com', 'owner');
        $conversation = $this->makeOpenConversation($workspace);
        $this->makeInboundMessage($conversation);

        $token = $this->loginToken($user, $workspace);

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index', ['conversation' => $conversation->id]))
            ->assertOk();

        $inbox = app(ConversationInboxService::class);
        $this->inWorkspace($workspace, function () use ($inbox, $conversation, $user): void {
            $this->assertSame(0, $inbox->unreadCountForConversation($conversation->fresh(), $user));
        });
    }

    public function test_user_archive_is_private_and_does_not_hide_conversation_from_teammate(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->makeMember('owner-archive@example.com', 'owner');
        [$teammate] = $this->makeMember('teammate-archive@example.com', 'agent', $workspace);
        $conversation = $this->makeOpenConversation($workspace);

        $ownerToken = $this->loginToken($owner, $workspace);

        $this->withToken($ownerToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/archive", ['archived' => true])
            ->assertOk();

        $this->assertSame('open', $conversation->fresh()->status);

        $this->withToken($ownerToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/conversations?filter=all')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $customerName = Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($conversation->customer_id)
            ->value('name');

        $this->actingAs($teammate)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index'))
            ->assertOk()
            ->assertSee((string) $customerName);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeMember(string $email, string $role, ?Workspace $workspace = null): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => 'password',
        ]);

        if ($workspace === null) {
            $workspace = Workspace::factory()->create([
                'owner_user_id' => $user->id,
                'type' => 'company',
            ]);
            $workspace->users()->attach($user->id, [
                'membership_role' => $role,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return [$user, $workspace];
        }

        $workspace->users()->attach($user->id, [
            'membership_role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    private function loginToken(User $user, Workspace $workspace): string
    {
        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        return (string) $login->json('data.token');
    }

    private function makeOpenConversation(Workspace $workspace): Conversation
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل مشترك',
            'phone' => '+966500000777',
        ]);

        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
    }

    private function makeInboundMessage(Conversation $conversation): Message
    {
        return Message::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'رسالة واردة',
        ]);
    }
}
