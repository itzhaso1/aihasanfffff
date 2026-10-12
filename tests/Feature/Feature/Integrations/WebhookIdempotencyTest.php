<?php

namespace Tests\Feature\Feature\Integrations;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_whatsapp_webhook_event_dispatches_job_once(): void
    {
        Bus::fake([ProcessIncomingWhatsAppMessage::class]);

        $workspace = Workspace::factory()->create();
        $account = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'biz-dedup-1',
            'display_name' => 'Dedup',
            'status' => 'connected',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => 'pn-dedup-1',
            'display_phone_number' => '+966500000000',
            'status' => 'connected',
        ]);

        $payload = [
            'entry' => [[
                'id' => 'evt-dedup-1',
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'pn-dedup-1'],
                        'messages' => [[
                            'from' => '966500000011',
                            'id' => 'wamid.dedup-1',
                            'text' => ['body' => 'hello'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $service = app(WhatsAppService::class);
        $service->processWebhook($payload, []);
        $service->processWebhook($payload, []);

        Bus::assertDispatched(ProcessIncomingWhatsAppMessage::class, 1);
        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('external_event_id', 'wamid.dedup-1')
            ->count());
    }

    public function test_two_different_whatsapp_messages_from_same_waba_both_dispatch_jobs(): void
    {
        Bus::fake([ProcessIncomingWhatsAppMessage::class]);

        $workspace = Workspace::factory()->create();
        $account = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'biz-dedup-2',
            'display_name' => 'Dedup 2',
            'status' => 'connected',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => 'pn-dedup-2',
            'display_phone_number' => '+966500000001',
            'status' => 'connected',
        ]);

        $sharedWabaEntryId = 'waba-entry-shared';

        $payloadOne = [
            'entry' => [[
                'id' => $sharedWabaEntryId,
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'pn-dedup-2'],
                        'messages' => [[
                            'from' => '966500000011',
                            'id' => 'wamid.dedup-2a',
                            'text' => ['body' => 'first'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $payloadTwo = [
            'entry' => [[
                'id' => $sharedWabaEntryId,
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'pn-dedup-2'],
                        'messages' => [[
                            'from' => '966500000011',
                            'id' => 'wamid.dedup-2b',
                            'text' => ['body' => 'second'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $service = app(WhatsAppService::class);
        $service->processWebhook($payloadOne, []);
        $service->processWebhook($payloadTwo, []);

        Bus::assertDispatched(ProcessIncomingWhatsAppMessage::class, 2);
        $this->assertSame(2, WebhookEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('provider', 'whatsapp')
            ->count());
    }

    public function test_same_external_message_id_does_not_duplicate_inbound_message(): void
    {
        $workspace = Workspace::factory()->create();
        $payload = [
            'from' => '966500000012',
            'id' => 'wamid.same-id',
            'text' => ['body' => 'once'],
        ];

        $service = app(WhatsAppService::class);
        $first = $service->storeIncomingMessage($workspace->id, $payload, 'pn-1');
        $second = $service->storeIncomingMessage($workspace->id, $payload, 'pn-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }
}
