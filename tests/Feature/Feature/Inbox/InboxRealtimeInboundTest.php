<?php

namespace Tests\Feature\Feature\Inbox;

use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageCreated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class InboxRealtimeInboundTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_inbound_dispatches_realtime_events_for_new_message(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class]);

        $this->workspaceWithPhone('pn-realtime-wa');
        $payload = $this->inboundPayload('pn-realtime-wa', 'wamid.realtime.new', '966500000099');

        app(WhatsAppService::class)->processWebhook($payload, []);

        Event::assertDispatched(MessageCreated::class);
        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_duplicate_whatsapp_webhook_does_not_dispatch_realtime_events_twice(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class]);

        $this->workspaceWithPhone('pn-realtime-dup');
        $payload = $this->inboundPayload('pn-realtime-dup', 'wamid.realtime.dup', '966500000088');
        $service = app(WhatsAppService::class);

        $service->processWebhook($payload, []);
        $service->processWebhook($payload, []);

        Event::assertDispatched(MessageCreated::class, 1);
        Event::assertDispatched(ConversationUpdated::class, 1);
    }

    public function test_realtime_broadcast_failure_does_not_remove_persisted_message(): void
    {
        $workspace = $this->workspaceWithPhone('pn-realtime-fail');
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'external_id' => '966500000077',
            'status' => 'open',
        ]);
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'hello',
            'external_message_id' => 'wamid.fail.test',
        ]);
        $message->wasRecentlyCreated = true;

        Event::listen(MessageCreated::class, static function (): void {
            throw new \RuntimeException('simulated websocket outage');
        });

        app(InboxRealtimePublisher::class)->publishNewInboundMessage($message, $conversation, [
            'provider' => 'test',
        ]);

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'external_message_id' => 'wamid.fail.test',
        ]);
    }

    private function workspaceWithPhone(string $phoneNumberId): Workspace
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $account = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'waba_'.uniqid(),
            'display_name' => 'Test WABA',
            'status' => 'connected',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '+966500000000',
            'status' => 'connected',
        ]);

        return $workspace;
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundPayload(string $phoneNumberId, string $messageId, string $from): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'id' => $messageId,
                            'from' => $from,
                            'text' => ['body' => 'realtime test'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
