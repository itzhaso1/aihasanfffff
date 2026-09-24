<?php

namespace Tests\Feature\Feature\Security;

use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\ProcessSubscriptionWebhook;
use App\Models\EmailLog;
use App\Models\EmailWebhookEvent;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCheckoutSession;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WebhookFailClosedSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_missing_and_empty_secret_rejects_with_zero_mutations(): void
    {
        [$order, $payment] = $this->pendingLocalPayment('REF-MISS');

        foreach ([null, '', '   '] as $secret) {
            config()->set('payment.providers.local.webhook_secret', $secret);
            Bus::fake([ProcessPaymentWebhook::class]);

            $payload = [
                'event_id' => 'evt_miss_'.uniqid(),
                'status' => 'paid',
                'reference' => 'REF-MISS',
            ];
            $response = $this->postPayment('local', $payload, 'any-secret');

            $response->assertUnauthorized()
                ->assertJsonPath('received', false);
            if (is_string($secret) && trim($secret) !== '') {
                $this->assertStringNotContainsString($secret, (string) $response->getContent());
            }
            Bus::assertNothingDispatched();
        }

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
    }

    public function test_payment_invalid_and_malformed_signature_rejects(): void
    {
        config()->set('payment.providers.local.webhook_secret', 'local_p07_secret');
        [$order, $payment] = $this->pendingLocalPayment('REF-BAD');

        $payload = ['event_id' => 'evt_bad', 'status' => 'paid', 'reference' => 'REF-BAD'];
        $this->postPayment('local', $payload, 'wrong-signature')->assertUnauthorized();
        $this->postPaymentRaw('local', $payload, [
            'X-Webhook-Timestamp' => 'not-a-time',
            'X-Webhook-Signature' => '%%%',
        ])->assertUnauthorized();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
    }

    public function test_payment_valid_signature_then_replay_does_not_duplicate(): void
    {
        config()->set('payment.providers.local.webhook_secret', 'local_p07_secret');
        [$order, $payment] = $this->pendingLocalPayment('REF-OK');

        $payload = ['event_id' => 'evt_ok', 'status' => 'paid', 'reference' => 'REF-OK'];
        $this->postPayment('local', $payload, 'local_p07_secret')->assertAccepted();
        $this->postPayment('local', $payload, 'local_p07_secret')->assertAccepted();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()->count());
    }

    public function test_payment_unbound_reference_does_not_write(): void
    {
        config()->set('payment.providers.local.webhook_secret', 'local_p07_secret');
        $this->pendingLocalPayment('REF-OWN');

        $this->postPayment('local', [
            'event_id' => 'evt_unbound',
            'status' => 'paid',
            'reference' => 'NO-SUCH-REF',
        ], 'local_p07_secret')->assertAccepted();

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->where('status', 'paid')->count());
    }

    public function test_payment_unsigned_direct_call_is_rejected(): void
    {
        config()->set('payment.providers.local.webhook_secret', 'local_p07_secret');
        $this->pendingLocalPayment('REF-DIRECT');

        $this->postJson('/api/webhooks/payments/local', [
            'event_id' => 'evt_direct',
            'status' => 'paid',
            'reference' => 'REF-DIRECT',
        ])->assertUnauthorized();

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
    }

    public function test_subscription_missing_empty_invalid_secret_does_not_activate(): void
    {
        [$workspace, $checkout] = $this->awaitingCheckout();

        foreach ([null, '', '   '] as $secret) {
            config()->set('subscription.webhook_secret', $secret);
            Bus::fake([ProcessSubscriptionWebhook::class]);

            $this->postSubscription([
                'event_id' => 'sub_miss_'.uniqid(),
                'provider_checkout_id' => $checkout->provider_checkout_id,
                'status' => 'paid',
                'amount' => $checkout->amount,
                'currency' => $checkout->currency,
            ], 'phase7-secret')->assertUnauthorized();

            Bus::assertNothingDispatched();
        }

        config()->set('subscription.webhook_secret', 'phase7-secret');
        $this->postSubscription([
            'event_id' => 'sub_bad_sig',
            'provider_checkout_id' => $checkout->provider_checkout_id,
            'status' => 'paid',
            'amount' => $checkout->amount,
            'currency' => $checkout->currency,
        ], 'wrong-secret')->assertUnauthorized();

        $this->assertSame('pending', $checkout->fresh()->payment_status);
        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
    }

    public function test_subscription_valid_then_replay_activates_once(): void
    {
        config()->set('subscription.webhook_secret', 'phase7-secret');
        [$workspace, $checkout] = $this->awaitingCheckout();

        $payload = [
            'event_id' => 'sub_ok_1',
            'provider_checkout_id' => $checkout->provider_checkout_id,
            'status' => 'paid',
            'amount' => $checkout->amount,
            'currency' => $checkout->currency,
        ];
        $this->postSubscription($payload, 'phase7-secret')->assertAccepted();
        $this->postSubscription($payload, 'phase7-secret')->assertAccepted();

        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('status', Subscription::STATUS_ACTIVE)->count());
        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()->where('provider', 'subscription:local')->count());
    }

    public function test_subscription_unbound_checkout_does_not_write(): void
    {
        config()->set('subscription.webhook_secret', 'phase7-secret');
        [$workspace] = $this->awaitingCheckout();

        $this->postSubscription([
            'event_id' => 'sub_unbound',
            'provider_checkout_id' => 'chk_does_not_exist',
            'status' => 'paid',
            'amount' => 10,
            'currency' => 'SAR',
        ], 'phase7-secret')->assertAccepted();

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_hyperpay_subscription_webhook_is_never_trusted(): void
    {
        [$workspace, $checkout] = $this->awaitingCheckout();

        $this->postJson('/api/webhooks/subscriptions/hyperpay', [
            'event_id' => 'hp_claim',
            'provider_checkout_id' => $checkout->provider_checkout_id,
            'status' => 'paid',
        ])->assertUnauthorized();

        $this->assertSame('pending', $checkout->fresh()->payment_status);
        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_whatsapp_verify_fails_closed_when_token_missing_or_empty(): void
    {
        foreach ([null, '', '   '] as $token) {
            config()->set('whatsapp.verify_token', $token);
            $this->get('/whatsapp-webhook?hub.mode=subscribe&hub.verify_token=&hub.challenge=leak')
                ->assertForbidden();
            $this->get('/whatsapp-webhook?hub.mode=subscribe&hub.challenge=leak')
                ->assertForbidden();
        }

        config()->set('whatsapp.verify_token', 'real-verify');
        $this->get('/whatsapp-webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=nope')
            ->assertForbidden();
        $this->get('/whatsapp-webhook?hub.mode=subscribe&hub.verify_token=real-verify&hub.challenge=ok-challenge')
            ->assertOk()
            ->assertSee('ok-challenge');
    }

    public function test_whatsapp_post_missing_secret_or_invalid_signature_does_not_write(): void
    {
        [$workspace] = $this->workspaceWithWhatsAppPhone('pnid-p07');
        $payload = $this->whatsAppInboundPayload('pnid-p07', 'wamid.p07');

        config()->set('whatsapp.app_secret', null);
        $this->postJson('/whatsapp-webhook', $payload)->assertForbidden();

        config()->set('whatsapp.app_secret', '   ');
        $this->postJson('/whatsapp-webhook', $payload)->assertForbidden();

        config()->set('whatsapp.app_secret', 'meta_p07');
        $this->postJson('/whatsapp-webhook', $payload)->assertForbidden();

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        $this->assertSame(0, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_whatsapp_valid_then_replay_and_unknown_phone(): void
    {
        config()->set('whatsapp.app_secret', 'meta_p07');
        [$workspace] = $this->workspaceWithWhatsAppPhone('pnid-ok');
        $payload = $this->whatsAppInboundPayload('pnid-ok', 'wamid.ok');

        $this->postWhatsApp($payload, 'meta_p07')->assertAccepted();
        $this->postWhatsApp($payload, 'meta_p07')->assertAccepted();

        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()->where('provider', 'whatsapp')->count());
        $this->assertSame(1, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());

        $unknown = $this->whatsAppInboundPayload('pnid-unknown', 'wamid.unk');
        $this->postWhatsApp($unknown, 'meta_p07')->assertAccepted();
        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()->where('provider', 'whatsapp')->count());
    }

    public function test_whatsapp_ambiguous_phone_number_id_does_not_route_webhook(): void
    {
        $this->workspaceWithWhatsAppPhone('pnid-ambig');
        $this->workspaceWithWhatsAppPhone('pnid-ambig');

        config()->set('whatsapp.app_secret', 'meta_p07');
        $this->postWhatsApp($this->whatsAppInboundPayload('pnid-ambig', 'wamid.ambig'), 'meta_p07')
            ->assertAccepted();
        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->where('external_event_id', 'evt-wamid.ambig')->count());
        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_resend_missing_empty_invalid_secret_does_not_mutate_log(): void
    {
        $log = EmailLog::withoutGlobalScopes()->create([
            'provider' => 'resend',
            'template' => 'general_notification',
            'recipient' => 'p07@example.com',
            'subject' => 'P07',
            'status' => 'pending',
            'provider_message_id' => 'resend-p07',
        ]);
        $payload = ['type' => 'email.delivered', 'data' => ['email_id' => 'resend-p07']];

        foreach ([null, '', '   '] as $secret) {
            config()->set('services.resend.webhook_secret', $secret);
            $this->postResend($payload, 'whsec_'.base64_encode('unused'))->assertUnauthorized();
        }

        config()->set('services.resend.webhook_secret', 'whsec_'.base64_encode('real-resend-key'));
        $this->postResend($payload, 'whsec_'.base64_encode('other-key'))->assertUnauthorized();

        $this->assertSame('pending', $log->fresh()->status);
        $this->assertSame(0, EmailWebhookEvent::query()->count());
    }

    public function test_resend_valid_then_replay_updates_once(): void
    {
        $secret = 'whsec_'.base64_encode('real-resend-key');
        config()->set('services.resend.webhook_secret', $secret);
        $log = EmailLog::withoutGlobalScopes()->create([
            'provider' => 'resend',
            'template' => 'general_notification',
            'recipient' => 'p07-ok@example.com',
            'subject' => 'P07',
            'status' => 'pending',
            'provider_message_id' => 'resend-p07-ok',
        ]);
        $payload = ['type' => 'email.delivered', 'data' => ['email_id' => 'resend-p07-ok']];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers = $this->svixHeaders($secret, $raw);

        $this->callResend($raw, $headers)->assertAccepted();
        $this->callResend($raw, $headers)->assertAccepted();

        $this->assertSame('sent', $log->fresh()->status);
        $this->assertSame(1, EmailWebhookEvent::query()->count());
    }

    public function test_rejected_webhooks_do_not_leak_secrets_in_logs_or_responses(): void
    {
        $secret = 'super-secret-webhook-key-p07';
        config()->set('payment.providers.local.webhook_secret', $secret);

        $response = $this->postPayment('local', [
            'event_id' => 'evt_leak',
            'status' => 'paid',
            'reference' => 'REF-LEAK',
        ], 'wrong');

        $response->assertUnauthorized();
        $this->assertStringNotContainsString($secret, (string) $response->getContent());
        $this->assertStringNotContainsString('Authorization', (string) $response->getContent());
        $this->assertStringNotContainsString($secret, json_encode($response->headers->all()) ?: '');
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function pendingLocalPayment(string $reference): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id, 'type' => 'company']);
        PaymentGateway::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'local',
            'status' => 'connected',
            'config' => [],
        ]);
        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => $reference,
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'shipping_status' => 'not_shipped',
            'currency' => 'SAR',
            'subtotal' => 40,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 40,
            'placed_at' => now(),
        ]);
        $payment = Payment::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'checkout_reference' => $reference,
            'provider' => 'local',
            'provider_payment_id' => 'pay_'.$reference,
            'idempotency_key' => 'idem_'.$reference,
            'status' => 'pending',
            'amount' => 40,
            'currency' => 'SAR',
            'payment_link' => 'https://example.test/pay/'.$reference,
            'provider_payload' => [],
        ]);

        return [$order, $payment];
    }

    /**
     * @return array{0: Workspace, 1: SubscriptionCheckoutSession}
     */
    private function awaitingCheckout(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id, 'type' => 'company']);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $plan = Plan::query()->create([
            'code' => 'p07_'.uniqid(),
            'name' => 'P07 Plan',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 50,
            'is_active' => true,
            'is_public' => true,
            'is_official' => true,
            'features' => ['dashboard'],
            'permissions' => [],
            'limits' => [],
        ]);
        $checkout = app(SubscriptionService::class)->createCheckoutSession($workspace, $plan);

        return [$workspace, $checkout];
    }

    /**
     * @return array{0: Workspace}
     */
    private function workspaceWithWhatsAppPhone(string $phoneNumberId): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id, 'type' => 'company']);
        $account = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'waba_'.$workspace->id,
            'display_name' => 'WA '.$workspace->id,
            'status' => 'connected',
            'metadata' => [],
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '+9665'.str_pad((string) $workspace->id, 8, '0'),
            'verified_name' => 'P07',
            'status' => 'connected',
        ]);

        return [$workspace];
    }

    /**
     * @return array<string, mixed>
     */
    private function whatsAppInboundPayload(string $phoneNumberId, string $messageId): array
    {
        return [
            'entry' => [[
                'id' => 'evt-'.$messageId,
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'id' => $messageId,
                            'from' => '966500009007',
                            'text' => ['body' => 'hello-p07'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postPayment(string $provider, array $payload, string $hmacSecret)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, $hmacSecret);

        return $this->postPaymentRaw($provider, $payload, [
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => $signature,
        ], $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function postPaymentRaw(string $provider, array $payload, array $headers, ?string $raw = null)
    {
        $raw ??= json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $server = [
            'CONTENT_TYPE' => 'application/json',
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/api/webhooks/payments/'.$provider, [], [], [], $server, $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSubscription(array $payload, string $secret)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, $secret);

        return $this->call('POST', '/api/webhooks/subscriptions/local', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWhatsApp(array $payload, string $secret)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';
        $signature = 'sha256='.hash_hmac('sha256', $raw, $secret);

        return $this->call('POST', '/whatsapp-webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postResend(array $payload, string $signingSecret)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';

        return $this->callResend($raw, $this->svixHeaders($signingSecret, $raw));
    }

    /**
     * @param  array{svix-id:string,svix-timestamp:string,svix-signature:string}  $headers
     */
    private function callResend(string $raw, array $headers)
    {
        return $this->call('POST', route('webhooks.resend'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SVIX_ID' => $headers['svix-id'],
            'HTTP_SVIX_TIMESTAMP' => $headers['svix-timestamp'],
            'HTTP_SVIX_SIGNATURE' => $headers['svix-signature'],
        ], $raw);
    }

    /**
     * @return array{svix-id:string,svix-timestamp:string,svix-signature:string}
     */
    private function svixHeaders(string $secret, string $rawBody): array
    {
        $msgId = 'msg_p07_'.uniqid();
        $timestamp = (string) time();
        $key = str_starts_with($secret, 'whsec_')
            ? (string) base64_decode(substr($secret, 6), true)
            : $secret;
        $signed = base64_encode(hash_hmac('sha256', $msgId.'.'.$timestamp.'.'.$rawBody, $key, true));

        return [
            'svix-id' => $msgId,
            'svix-timestamp' => $timestamp,
            'svix-signature' => 'v1,'.$signed,
        ];
    }
}
