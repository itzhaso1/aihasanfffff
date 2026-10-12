<?php

namespace Tests\Feature\Feature\WhatsApp;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WhatsAppWebhookDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_whatsapp_message_is_ingested_once(): void
    {
        Bus::fake([ProcessIncomingWhatsAppMessage::class]);

        $workspace = $this->workspaceWithPhone('pn-first');
        $payload = $this->inboundPayload('pn-first', 'wamid.first', '966500000001');

        app(WhatsAppService::class)->processWebhook($payload, []);

        Bus::assertDispatched(ProcessIncomingWhatsAppMessage::class, 1);
        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('provider', 'whatsapp')
            ->where('external_event_id', 'wamid.first')
            ->count());
    }

    public function test_replayed_whatsapp_webhook_does_not_dispatch_duplicate_job_or_message(): void
    {
        $workspace = $this->workspaceWithPhone('pn-replay');
        $payload = $this->inboundPayload('pn-replay', 'wamid.replay', '966500000002');
        $service = app(WhatsAppService::class);

        $service->processWebhook($payload, []);
        $service->processWebhook($payload, []);

        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('provider', 'whatsapp')
            ->count());
        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('external_message_id', 'wamid.replay')
            ->count());
    }

    public function test_same_external_event_id_is_allowed_in_different_workspaces(): void
    {
        Bus::fake([ProcessIncomingWhatsAppMessage::class]);

        $workspaceA = $this->workspaceWithPhone('pn-tenant-a');
        $workspaceB = $this->workspaceWithPhone('pn-tenant-b');
        $sharedMessageId = 'wamid.shared-tenant';

        app(WhatsAppService::class)->processWebhook(
            $this->inboundPayload('pn-tenant-a', $sharedMessageId, '966500000010'),
            [],
        );
        app(WhatsAppService::class)->processWebhook(
            $this->inboundPayload('pn-tenant-b', $sharedMessageId, '966500000011'),
            [],
        );

        Bus::assertDispatched(ProcessIncomingWhatsAppMessage::class, 2);
        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspaceA->id)
            ->where('external_event_id', $sharedMessageId)
            ->count());
        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspaceB->id)
            ->where('external_event_id', $sharedMessageId)
            ->count());
    }

    public function test_same_external_event_id_in_same_workspace_is_idempotently_ignored(): void
    {
        Bus::fake([ProcessIncomingWhatsAppMessage::class]);

        $workspace = $this->workspaceWithPhone('pn-same-tenant');
        $payload = $this->inboundPayload('pn-same-tenant', 'wamid.same-tenant', '966500000012');
        $service = app(WhatsAppService::class);

        $service->processWebhook($payload, []);
        $service->processWebhook($payload, []);

        Bus::assertDispatched(ProcessIncomingWhatsAppMessage::class, 1);
        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('provider', 'whatsapp')
            ->where('external_event_id', 'wamid.same-tenant')
            ->count());
    }

    public function test_malformed_webhook_without_message_id_is_ignored_safely(): void
    {
        Bus::fake([ProcessIncomingWhatsAppMessage::class]);

        $workspace = $this->workspaceWithPhone('pn-malformed');
        $payload = [
            'entry' => [[
                'id' => 'waba-entry-only',
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'pn-malformed'],
                        'messages' => [[
                            'from' => '966500000013',
                            'text' => ['body' => 'missing id'],
                        ]],
                    ],
                ]],
            ]],
        ];

        app(WhatsAppService::class)->processWebhook($payload, []);

        Bus::assertNothingDispatched();
        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->count());
    }

    public function test_concurrent_duplicate_insert_is_handled_without_duplicate_dispatch(): void
    {
        Bus::fake([ProcessIncomingWhatsAppMessage::class]);

        $workspace = $this->workspaceWithPhone('pn-race');
        $payload = $this->inboundPayload('pn-race', 'wamid.race', '966500000014');

        WebhookEvent::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'whatsapp',
            'external_event_id' => 'wamid.race',
            'idempotency_key' => 'wamid.race',
            'event_type' => 'message',
            'headers' => [],
            'payload' => $payload,
            'status' => 'pending',
        ]);

        app(WhatsAppService::class)->processWebhook($payload, []);

        Bus::assertNothingDispatched();
        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('external_event_id', 'wamid.race')
            ->count());
    }

    public function test_webhook_events_external_event_unique_index_is_workspace_scoped(): void
    {
        $this->assertTrue(Schema::hasIndex(
            'webhook_events',
            'webhook_events_workspace_provider_external_event_unique',
        ));
    }

    private function workspaceWithPhone(string $phoneNumberId): Workspace
    {
        $workspace = Workspace::factory()->create();
        $account = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'waba_'.$workspace->id,
            'display_name' => 'WA '.$workspace->id,
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
                'id' => 'waba-entry-'.$phoneNumberId,
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'from' => $from,
                            'id' => $messageId,
                            'text' => ['body' => 'hello '.$messageId],
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
