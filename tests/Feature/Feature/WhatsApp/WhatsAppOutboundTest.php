<?php

namespace Tests\Feature\Feature\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppOutboundMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppOutboundTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_service_queues_message_and_job_sends_via_graph_api(): void
    {
        config()->set('services.whatsapp.token', 'test-wa-token');
        config()->set('whatsapp.api_version', 'v20.0');

        [$workspace] = $this->workspaceWithWhatsApp();

        $account = WhatsAppAccount::query()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'waba_1',
            'display_name' => 'Test WA',
            'status' => 'connected',
            'metadata' => [],
        ]);

        $phone = WhatsAppPhoneNumber::query()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => 'pnid_123',
            'display_phone_number' => '+966500000000',
            'verified_name' => 'Test',
            'status' => 'connected',
        ]);

        Queue::fake();

        $outbound = app(WhatsAppOutboundService::class)->sendText(
            workspace: $workspace,
            phoneNumberId: 'pnid_123',
            to: '966511111111',
            body: 'مرحبا',
        );

        $this->assertDatabaseHas('whatsapp_outbound_messages', [
            'id' => $outbound->id,
            'workspace_id' => $workspace->id,
            'whats_app_phone_number_id' => $phone->id,
            'to' => '966511111111',
            'status' => 'queued',
            'body' => 'مرحبا',
        ]);

        Queue::assertPushed(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) use ($outbound): bool {
            return $job->phoneNumberId === 'pnid_123'
                && $job->to === '966511111111'
                && $job->message === 'مرحبا'
                && $job->outboundMessageId === $outbound->id;
        });

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEST123']],
            ], 200),
        ]);

        $job = new SendWhatsAppMessage('pnid_123', '966511111111', 'مرحبا', $outbound->id);
        $job->handle();

        $outbound->refresh();
        $this->assertSame('sent', $outbound->status);
        $this->assertSame('wamid.TEST123', $outbound->provider_message_id);
        $this->assertNotNull($outbound->sent_at);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/pnid_123/messages')
                && $request['to'] === '966511111111'
                && $request['text']['body'] === 'مرحبا';
        });
    }

    /**
     * @return array{0:Workspace,1:User}
     */
    private function workspaceWithWhatsApp(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'code' => 'company_wa_test',
            'name' => 'WA Test',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => ['whatsapp', 'ai', 'conversations'],
            'limits' => ['whatsapp_messages' => 100],
        ]);

        Subscription::query()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        return [$workspace, $user];
    }
}
