<?php

namespace Tests\Feature\Feature\Tenancy;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbound_webhook_writes_nothing_even_when_same_provider_exists_in_two_workspaces(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspacesWithLocalGateways();

        $this->processLocalWebhook([
            'event_id' => 'evt_unbound_provider',
            'status' => 'paid',
            'reference' => 'NO-SUCH-REFERENCE',
        ]);

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
    }

    public function test_webhook_bound_to_payment_in_a_only_mutates_workspace_a(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspacesWithLocalGateways();
        [$orderA, $paymentA] = $this->orderWithPayment($workspaceA, 'REF-A-ONLY', 'local');
        [$orderB, $paymentB] = $this->orderWithPayment($workspaceB, 'REF-B-ONLY', 'local');

        $this->processLocalWebhook([
            'event_id' => 'evt_bound_a',
            'status' => 'paid',
            'reference' => 'REF-A-ONLY',
        ]);

        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
        $this->assertSame('paid', $paymentA->fresh()->status);
        $this->assertSame('paid', $orderA->fresh()->payment_status);
        $this->assertSame('pending', $paymentB->fresh()->status);
        $this->assertSame('pending', $orderB->fresh()->payment_status);
    }

    public function test_webhook_referencing_workspace_b_order_number_does_not_touch_b_without_b_payment(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspacesWithLocalGateways();
        [$orderA, $paymentA] = $this->orderWithPayment($workspaceA, 'REF-A-SAFE', 'local');
        $orderB = $this->orderIn($workspaceB, 'REF-B-TARGET');

        $this->processLocalWebhook([
            'event_id' => 'evt_try_b_order',
            'status' => 'paid',
            'reference' => 'REF-B-TARGET',
        ]);

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        $this->assertSame('pending', $paymentA->fresh()->status);
        $this->assertSame('pending', $orderA->fresh()->payment_status);
        $this->assertSame('pending', $orderB->fresh()->payment_status);
    }

    public function test_duplicate_order_number_across_tenants_rejects_instead_of_cross_tenant_resolution(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspacesWithLocalGateways();
        [$orderA, $paymentA] = $this->orderWithPayment($workspaceA, 'SHARED-ORD', 'local');
        [$orderB, $paymentB] = $this->orderWithPayment($workspaceB, 'SHARED-ORD', 'local');

        $this->processLocalWebhook([
            'event_id' => 'evt_ambiguous',
            'status' => 'paid',
            'reference' => 'SHARED-ORD',
        ]);

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        $this->assertSame('pending', $paymentA->fresh()->status);
        $this->assertSame('pending', $paymentB->fresh()->status);
        $this->assertSame('pending', $orderA->fresh()->payment_status);
        $this->assertSame('pending', $orderB->fresh()->payment_status);
    }

    public function test_ambiguous_checkout_reference_across_providers_is_rejected(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspacesWithLocalGateways();
        $this->orderWithPayment($workspaceA, 'AMBIG-REF', 'local');
        $this->orderWithPayment($workspaceB, 'AMBIG-REF', 'local');

        $this->processLocalWebhook([
            'event_id' => 'evt_ambig_ref',
            'status' => 'paid',
            'reference' => 'AMBIG-REF',
        ]);

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
    }

    /**
     * @return array{0: Workspace, 1: Workspace}
     */
    private function twoWorkspacesWithLocalGateways(): array
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $workspaceA = Workspace::factory()->create(['owner_user_id' => $userA->id, 'type' => 'company']);
        $workspaceB = Workspace::factory()->create(['owner_user_id' => $userB->id, 'type' => 'company']);

        foreach ([$workspaceA, $workspaceB] as $workspace) {
            PaymentGateway::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'provider' => 'local',
                'status' => 'connected',
                'config' => [],
            ]);
        }

        return [$workspaceA, $workspaceB];
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function orderWithPayment(Workspace $workspace, string $reference, string $provider): array
    {
        $order = $this->orderIn($workspace, $reference);
        $payment = Payment::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'checkout_reference' => $reference,
            'provider' => $provider,
            'provider_payment_id' => 'pay_'.$reference.'_'.$workspace->id,
            'idempotency_key' => 'idem_'.$reference.'_'.$workspace->id,
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'SAR',
            'payment_link' => 'https://example.test/pay/'.$reference,
            'provider_payload' => [],
        ]);

        return [$order, $payment];
    }

    private function orderIn(Workspace $workspace, string $orderNumber): Order
    {
        return Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => $orderNumber,
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'shipping_status' => 'not_shipped',
            'currency' => 'SAR',
            'subtotal' => 50,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50,
            'placed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function processLocalWebhook(array $payload): void
    {
        config()->set('payment.providers.local.webhook_secret', 'local_tenancy_secret');
        config()->set('payment.providers.local.webhook_tolerance_seconds', 300);

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'local_tenancy_secret');

        app(PaymentService::class)->processWebhook('local', [
            'x-webhook-timestamp' => (string) $timestamp,
            'x-webhook-signature' => $signature,
        ], $payload, $rawBody);
    }
}
