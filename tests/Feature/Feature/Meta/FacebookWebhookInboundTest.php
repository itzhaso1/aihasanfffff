<?php

namespace Tests\Feature\Feature\Meta;

use App\Jobs\ProcessAIResponse;
use App\Jobs\ProcessIncomingFacebookMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Support\Meta\MetaConnectionKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Feature\Meta\Concerns\InteractsWithMetaWebhook;
use Tests\TestCase;

class FacebookWebhookInboundTest extends TestCase
{
    use InteractsWithMetaWebhook;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProcessAIResponse::class]);
        config()->set('meta.app_secret', 'meta_facebook_secret');
        config()->set('meta.webhook_verify_token', 'meta-facebook-verify');
    }

    public function test_page_webhook_creates_customer_conversation_and_message(): void
    {
        [$workspace] = $this->workspaceWithFacebookPage('112233445566778');
        $payload = $this->facebookTextPayload(
            pageId: '112233445566778',
            senderId: '998877665544332',
            messageId: 'mid.fb.1',
            text: 'Hello from Messenger',
        );

        $this->postMetaWebhook($payload, 'meta_facebook_secret')->assertAccepted();

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());

        $conversation = Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('facebook_messenger', $conversation->channel);
        $this->assertSame('facebook:998877665544332', $conversation->external_id);

        $message = Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('Hello from Messenger', $message->content);
        $this->assertSame('mid.fb.1', $message->external_message_id);
    }

    public function test_duplicate_webhook_event_is_ignored(): void
    {
        [$workspace] = $this->workspaceWithFacebookPage('112233445566778');
        $payload = $this->facebookTextPayload('112233445566778', '998877665544332', 'mid.fb.dup', 'once');

        $this->postMetaWebhook($payload, 'meta_facebook_secret')->assertAccepted();
        $this->postMetaWebhook($payload, 'meta_facebook_secret')->assertAccepted();

        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_inbound_job_is_queued_on_inbound_queue(): void
    {
        Bus::fake([ProcessIncomingFacebookMessage::class, ProcessAIResponse::class]);
        $this->workspaceWithFacebookPage('112233445566778');
        $payload = $this->facebookTextPayload('112233445566778', '998877665544332', 'mid.fb.queue', 'queued');

        $this->postMetaWebhook($payload, 'meta_facebook_secret')->assertAccepted();

        Bus::assertDispatched(ProcessIncomingFacebookMessage::class, function (ProcessIncomingFacebookMessage $job): bool {
            return $job->queue === 'inbound';
        });
    }

    public function test_inbound_message_reopens_an_archived_conversation_and_queues_ai(): void
    {
        [$workspace] = $this->workspaceWithFacebookPage('112233445566778');
        $senderId = '998877665544332';
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Facebook User',
            'phone' => 'fb-'.$senderId,
            'client_reference' => 'facebook:'.$senderId,
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'facebook_messenger',
            'external_id' => 'facebook:'.$senderId,
            'status' => 'archived',
            'ai_enabled' => true,
            'handoff_status' => 'resolved',
        ]);

        $payload = $this->facebookTextPayload(
            pageId: '112233445566778',
            senderId: $senderId,
            messageId: 'mid.fb.reopen',
            text: 'هلا كيفك',
        );

        $this->postMetaWebhook($payload, 'meta_facebook_secret')->assertAccepted();

        $this->assertSame('open', $conversation->fresh()->status);
        Bus::assertDispatched(ProcessAIResponse::class, function (ProcessAIResponse $job) use ($conversation): bool {
            return $job->conversationId === $conversation->id;
        });
    }

    /**
     * @return array{0: Workspace}
     */
    private function workspaceWithFacebookPage(string $pageId): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $connection = WorkspaceMetaConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'kind' => MetaConnectionKind::FACEBOOK_PAGE,
            'external_id' => $pageId,
            'display_name' => 'Test Page',
            'status' => 'connected',
            'scopes' => ['pages_messaging'],
            'metadata' => ['channel' => 'facebook_messenger'],
            'connected_by_user_id' => $user->id,
        ]);
        $connection->storeAccessToken('page-access-token-test');
        $connection->save();

        return [$workspace];
    }

    /**
     * @return array<string, mixed>
     */
    private function facebookTextPayload(string $pageId, string $senderId, string $messageId, string $text): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'time' => 1700000000,
                'messaging' => [[
                    'sender' => ['id' => $senderId],
                    'recipient' => ['id' => $pageId],
                    'timestamp' => 1700000000123,
                    'message' => [
                        'mid' => $messageId,
                        'text' => $text,
                    ],
                ]],
            ]],
        ];
    }
}
