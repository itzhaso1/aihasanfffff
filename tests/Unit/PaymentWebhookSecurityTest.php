<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\Workspace;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PaymentWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stripe_webhook_requires_valid_signature_and_prevents_replay_processing(): void
    {
        config()->set('payment.providers.stripe.webhook_secret', 'whsec_test');
        config()->set('payment.providers.stripe.webhook_tolerance_seconds', 300);

        $workspace = Workspace::factory()->create();
        PaymentGateway::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'stripe',
            'status' => 'connected',
            'config' => [],
        ]);

        $order = Order::factory()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => null,
            'order_number' => 'ORD-WEBHOOK-1',
            'payment_status' => 'pending',
            'status' => 'draft',
            'total_amount' => 150,
            'currency' => 'USD',
        ]);

        Payment::factory()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'provider' => 'stripe',
            'status' => 'pending',
            'amount' => 150,
            'currency' => 'USD',
            'provider_payment_id' => 'pi_test_1',
            'idempotency_key' => 'payment-key-1',
        ]);

        $payload = [
            'id' => 'evt_test_1',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'metadata' => [
                        'reference' => 'ORD-WEBHOOK-1',
                    ],
                ],
            ],
        ];

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'whsec_test');
        $headers = [
            'stripe-signature' => "t={$timestamp},v1={$signature}",
        ];

        Event::fake();

        $paymentService = app(PaymentService::class);
        $paymentService->processWebhook('stripe', $headers, $payload, $rawBody);
        $paymentService->processWebhook('stripe', $headers, $payload, $rawBody);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'payment:stripe',
            'external_event_id' => 'evt_test_1',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'provider' => 'stripe',
            'status' => 'paid',
        ]);
    }

    public function test_stripe_webhook_is_marked_invalid_with_wrong_signature(): void
    {
        config()->set('payment.providers.stripe.webhook_secret', 'whsec_test');
        config()->set('payment.providers.stripe.webhook_tolerance_seconds', 300);

        $workspace = Workspace::factory()->create();
        PaymentGateway::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'stripe',
            'status' => 'connected',
            'config' => [],
        ]);

        $order = Order::factory()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => null,
            'order_number' => 'ORD-WEBHOOK-2',
            'payment_status' => 'pending',
            'status' => 'draft',
            'total_amount' => 200,
            'currency' => 'USD',
        ]);

        Payment::factory()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'provider' => 'stripe',
            'status' => 'pending',
            'amount' => 200,
            'currency' => 'USD',
            'provider_payment_id' => 'pi_test_2',
            'idempotency_key' => 'payment-key-2',
        ]);

        $payload = [
            'id' => 'evt_test_2',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'metadata' => [
                        'reference' => 'ORD-WEBHOOK-2',
                    ],
                ],
            ],
        ];

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers = [
            'stripe-signature' => 't='.time().',v1=invalid-signature',
        ];

        app(PaymentService::class)->processWebhook('stripe', $headers, $payload, $rawBody);

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'pending',
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'provider' => 'stripe',
            'status' => 'pending',
        ]);
    }
}
