<?php

namespace Tests\Feature\Feature\Api;

use App\Models\MerchantProfile;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrderPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_order_creation_and_payment_webhook_update_statuses(): void
    {
        $this->seed(FoundationSeeder::class);
        Config::set('services.hyperpay.merchant_sandbox_auto_approve', true);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id, 'type' => 'company']);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()->where('code', 'pro')->firstOrFail();
        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $profile = MerchantProfile::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
        ]);
        $profile->forceFill([
            'verification_status' => MerchantProfile::VERIFICATION_APPROVED,
            'provider_onboarding_status' => MerchantProfile::PROVIDER_ACTIVE,
            'approved_at' => now(),
        ])->save();

        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Runner Shoe',
            'slug' => 'runner-shoe',
            'sku' => 'SKU-RUN-42',
            'price' => 150,
            'currency' => 'USD',
            'stock' => 10,
            'status' => 'active',
        ]);

        $token = $user->createToken('api')->plainTextToken;

        $orderResponse = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/orders', [
                'currency' => 'USD',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 2,
                    ],
                ],
            ])->assertCreated();

        $orderId = $orderResponse->json('data.id');
        $orderNumber = $orderResponse->json('data.order_number');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
        ]);

        $paymentResponse = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/payments', [
                'order_id' => $orderId,
            ])
            ->assertCreated();

        $this->assertNotEmpty($paymentResponse->json('data.payment_link'));

        config()->set('payment.providers.local.webhook_secret', 'local_order_flow_secret');
        config()->set('payment.providers.local.webhook_tolerance_seconds', 300);

        $payload = [
            'event_id' => 'evt_local_12345',
            'status' => 'paid',
            'reference' => $orderNumber,
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'local_order_flow_secret');

        $this->withHeaders([
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])->call(
            'POST',
            '/api/webhooks/payments/local',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawBody
        )->assertAccepted();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_status' => 'paid',
        ]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $orderId,
            'status' => 'paid',
        ]);

        $this->withHeaders([
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Signature' => $signature,
        ])->call(
            'POST',
            '/api/webhooks/payments/local',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawBody
        )->assertAccepted();

        $this->assertDatabaseCount('webhook_events', 1);
    }
}
