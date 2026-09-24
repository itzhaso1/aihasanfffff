<?php

namespace Tests\Feature\Feature\Security;

use App\Jobs\ProcessAIResponse;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionBillingAttempt;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Inventory\InventoryService;
use App\Services\Payment\PaymentService;
use App\Services\Subscription\SubscriptionLifecycleService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Http\WebhookHeaderSanitizer;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ProductionHardeningRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_whatsapp_inbound_retry_does_not_duplicate_message(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('WA Retry');
        $payload = [
            'from' => '966500000001',
            'id' => 'wamid.retry-1',
            'text' => ['body' => 'hello'],
        ];

        $first = app(WhatsAppService::class)->storeIncomingMessage($workspace->id, $payload, 'pn-1');
        $second = app(WhatsAppService::class)->storeIncomingMessage($workspace->id, $payload, 'pn-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame($owner->id, $workspace->owner_user_id);
    }

    public function test_ai_reply_job_is_idempotent_for_the_same_inbound_message(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('AI Dedup');
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Customer',
            'phone' => '966500000002',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $inbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'hi',
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturn('reply');
        $this->app->instance(AIService::class, $ai);

        $job = new ProcessAIResponse($conversation->id, $inbound->id);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('ai_generated', true)
            ->count());
        $this->assertSame($owner->id, $workspace->owner_user_id);
    }

    public function test_member_cannot_use_mobile_email_inbox(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Mobile Email');
        $this->enableWorkspaceFeature($workspace, 'email');
        $member = $this->attachMember($workspace, 'member');

        Sanctum::actingAs($member);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/emails/inbox')
            ->assertForbidden();

        Sanctum::actingAs($owner);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/emails/inbox')
            ->assertOk();
    }

    public function test_inventory_skips_untracked_products_and_blocks_oversell(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Inventory');
        $untracked = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Digital',
            'slug' => 'digital',
            'sku' => 'DIG-1',
            'price' => 10,
            'currency' => 'SAR',
            'stock' => 1,
            'status' => 'active',
            'inventory_tracking' => false,
        ]);
        $tracked = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Stocked',
            'slug' => 'stocked',
            'sku' => 'STK-1',
            'price' => 10,
            'currency' => 'SAR',
            'stock' => 1,
            'status' => 'active',
            'inventory_tracking' => true,
        ]);

        $service = app(InventoryService::class);
        $context = app(WorkspaceContext::class);
        $context->runForId((int) $workspace->id, function () use ($service, $untracked): void {
            $this->assertNull($service->adjustStock(
                productId: $untracked->id,
                variantId: null,
                type: 'remove',
                quantity: 5,
            ));
            $this->assertSame(1, (int) $untracked->fresh()->stock);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock.');
        $context->runForId((int) $workspace->id, function () use ($service, $tracked): void {
            $service->adjustStock(
                productId: $tracked->id,
                variantId: null,
                type: 'remove',
                quantity: 4,
            );
        });
        $this->assertSame($owner->id, $workspace->owner_user_id);
    }

    public function test_create_payment_link_reuses_pending_payment_for_same_order(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Pay');
        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'ORD-PAY-1',
            'source' => 'manual',
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'currency' => 'SAR',
            'subtotal' => 50,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50,
            'placed_at' => now(),
        ]);

        $service = app(PaymentService::class);
        $first = $service->createPaymentLink($order, null, 'local_sandbox');
        $second = $service->createPaymentLink($order->fresh(), null, 'local_sandbox');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('order_id', $order->id)->count());
        $this->assertSame($owner->id, $workspace->owner_user_id);
    }

    public function test_webhook_headers_redact_authorization(): void
    {
        $redacted = WebhookHeaderSanitizer::redact([
            'Authorization' => 'Bearer secret',
            'X-Hub-Signature-256' => 'sha256=abc',
            'Content-Type' => 'application/json',
        ]);

        $this->assertSame('[redacted]', $redacted['Authorization']);
        $this->assertSame('[redacted]', $redacted['X-Hub-Signature-256']);
        $this->assertSame('application/json', $redacted['Content-Type']);
    }

    public function test_whatsapp_inbound_job_marks_processed_after_persist(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('WA Job');
        Bus::fake();
        $event = WebhookEvent::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'whatsapp',
            'external_event_id' => 'evt-job-1',
            'idempotency_key' => 'evt-job-1',
            'payload' => [],
            'status' => 'pending',
        ]);

        (new ProcessIncomingWhatsAppMessage(
            $workspace->id,
            ['from' => '966511111111', 'id' => 'wamid.job-1', 'text' => ['body' => 'hello']],
            $event->id,
            'pnid-a',
        ))->handle(app(WhatsAppService::class));

        $this->assertSame('processed', $event->fresh()->status);
        $this->assertSame(1, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame($owner->id, $workspace->owner_user_id);
    }

    public function test_renewal_scheduler_does_not_duplicate_active_period_attempts(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Renew');
        $plan = Plan::query()->create([
            'code' => 'renew_'.uniqid(),
            'name' => 'Renew',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 99,
            'is_active' => true,
            'is_public' => true,
            'is_official' => true,
            'features' => ['dashboard'],
            'permissions' => ['workspace.view'],
            'limits' => [],
        ]);
        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDay(),
            'running_guard' => 'running',
        ]);

        $lifecycle = app(SubscriptionLifecycleService::class);
        $lifecycle->processRenewals();
        $lifecycle->processRenewals();

        $this->assertSame(1, SubscriptionBillingAttempt::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('purpose', SubscriptionBillingAttempt::PURPOSE_RENEWAL)
            ->count());
        $this->assertSame($owner->id, $workspace->owner_user_id);
    }

    public function test_member_cannot_see_channel_connect_controls(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Channels');
        $member = $this->attachMember($workspace, 'member');

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.channels.index'))
            ->assertOk()
            ->assertDontSee(route('workspace.channels.whatsapp.connect'), false)
            ->assertDontSee(route('workspace.channels.instagram.connect'), false);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.channels.index'))
            ->assertOk()
            ->assertSee(route('workspace.channels.whatsapp.connect'), false);
        $this->assertSame($owner->id, $workspace->owner_user_id);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspace(string $name): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    private function attachMember(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }
}
