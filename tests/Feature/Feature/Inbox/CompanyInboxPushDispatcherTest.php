<?php

namespace Tests\Feature\Feature\Inbox;

use App\Jobs\Push\SendPushNotificationJob;
use App\Models\Conversation;
use App\Models\ConversationUserState;
use App\Models\DevicePushToken;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inbox\InboxRealtimePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CompanyInboxPushDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_message_dispatches_push_for_company_member_device_only(): void
    {
        Event::fake();
        Bus::fake([SendPushNotificationJob::class]);

        [$workspaceA, $ownerA] = $this->companyWorkspace('a@example.com');
        [$workspaceB, $ownerB] = $this->companyWorkspace('b@example.com');
        $worker = User::factory()->create();

        $this->device($ownerA, $workspaceA, 'token-a');
        $this->device($ownerB, $workspaceB, 'token-b');
        $this->device($worker, $workspaceA, 'token-worker');

        $conversation = $this->conversation($workspaceA);
        $message = $this->inbound($workspaceA, $conversation, 'hello from customer');
        $message->wasRecentlyCreated = true;

        app(InboxRealtimePublisher::class)->publishNewInboundMessage($message, $conversation);

        Bus::assertDispatched(SendPushNotificationJob::class, function (SendPushNotificationJob $job) use ($ownerA, $workspaceA, $conversation, $message): bool {
            $this->assertSame($ownerA->id, $job->userId);
            $this->assertSame($workspaceA->id, $job->workspaceId);
            $this->assertSame('messages', $job->category);
            $this->assertSame((string) $conversation->id, $job->data['conversation_id']);
            $this->assertSame((string) $message->id, $job->data['message_id']);
            $this->assertSame((string) $workspaceA->id, $job->data['workspace_id']);
            $this->assertSame('message', $job->data['type']);
            $this->assertSame('hello from customer', $job->body);

            return true;
        });
        Bus::assertDispatchedTimes(SendPushNotificationJob::class, 1);
    }

    public function test_muted_member_does_not_receive_push(): void
    {
        Event::fake();
        Bus::fake([SendPushNotificationJob::class]);

        [$workspace, $owner] = $this->companyWorkspace('muted@example.com');
        $this->device($owner, $workspace, 'token-muted');
        $conversation = $this->conversation($workspace);
        ConversationUserState::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'muted_at' => now(),
        ]);
        $message = $this->inbound($workspace, $conversation, 'muted');
        $message->wasRecentlyCreated = true;

        app(InboxRealtimePublisher::class)->publishNewInboundMessage($message, $conversation);

        Bus::assertNotDispatched(SendPushNotificationJob::class);
    }

    public function test_outbound_message_does_not_dispatch_company_push(): void
    {
        Event::fake();
        Bus::fake([SendPushNotificationJob::class]);

        [$workspace, $owner] = $this->companyWorkspace('out@example.com');
        $this->device($owner, $workspace, 'token-out');
        $conversation = $this->conversation($workspace);
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'agent reply',
        ]);
        $message->wasRecentlyCreated = true;

        app(InboxRealtimePublisher::class)->publishNewMessage($message, $conversation);

        Bus::assertNotDispatched(SendPushNotificationJob::class);
    }

    /**
     * @return array{0: Workspace, 1: User}
     */
    private function companyWorkspace(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$workspace, $user];
    }

    private function device(User $user, Workspace $workspace, string $token): DevicePushToken
    {
        return DevicePushToken::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'fcm',
            'token' => $token,
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
    }

    private function conversation(Workspace $workspace): Conversation
    {
        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'external_id' => 'ext-'.uniqid(),
            'status' => 'open',
        ]);
    }

    private function inbound(Workspace $workspace, Conversation $conversation, string $content): Message
    {
        return Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => $content,
        ]);
    }
}
