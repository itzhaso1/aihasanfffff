<?php

namespace Tests\Unit\Payment;

use App\Services\Payment\Providers\LocalPaymentGateway;
use App\Services\Payment\Providers\StripePaymentGateway;
use Tests\TestCase;

class WebhookVerificationTest extends TestCase
{
    public function test_stripe_webhook_requires_valid_signature_and_timestamp(): void
    {
        config()->set('payment.providers.stripe.webhook_secret', 'whsec_test_123');
        config()->set('payment.providers.stripe.webhook_tolerance_seconds', 300);

        $payload = [
            'id' => 'evt_123',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'metadata' => [
                        'reference' => 'APT-ORD-00000001',
                    ],
                ],
            ],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'whsec_test_123');

        $gateway = new StripePaymentGateway();

        $valid = $gateway->verifyWebhook([
            'stripe-signature' => "t={$timestamp},v1={$signature}",
        ], $payload, $rawBody);
        $this->assertTrue($valid['verified']);
        $this->assertSame('paid', $valid['status']);

        $invalid = $gateway->verifyWebhook([
            'stripe-signature' => "t={$timestamp},v1=invalid",
        ], $payload, $rawBody);
        $this->assertFalse($invalid['verified']);
    }

    public function test_local_webhook_verification_uses_hmac_when_secret_is_configured(): void
    {
        config()->set('payment.providers.local.webhook_secret', 'local_test_secret');
        config()->set('payment.providers.local.webhook_tolerance_seconds', 300);

        $payload = [
            'event_id' => 'local_evt_1',
            'status' => 'paid',
            'reference' => 'APT-ORD-00000011',
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'local_test_secret');

        $gateway = new LocalPaymentGateway();

        $valid = $gateway->verifyWebhook([
            'x-webhook-timestamp' => (string) $timestamp,
            'x-webhook-signature' => $signature,
        ], $payload, $rawBody);
        $this->assertTrue($valid['verified']);

        $invalid = $gateway->verifyWebhook([
            'x-webhook-timestamp' => (string) $timestamp,
            'x-webhook-signature' => 'bad',
        ], $payload, $rawBody);
        $this->assertFalse($invalid['verified']);
    }

    public function test_local_webhook_rejects_when_secret_is_empty(): void
    {
        config()->set('payment.providers.local.webhook_secret', '');
        config()->set('payment.providers.local.webhook_tolerance_seconds', 300);

        $payload = [
            'event_id' => 'local_evt_empty_secret',
            'status' => 'paid',
            'reference' => 'APT-ORD-00000099',
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        $gateway = new LocalPaymentGateway();
        $result = $gateway->verifyWebhook([
            'x-webhook-timestamp' => (string) $timestamp,
            'x-webhook-signature' => 'anything',
        ], $payload, $rawBody);

        $this->assertFalse($result['verified']);
        $this->assertSame('Local payment webhook secret is not configured.', $result['reason']);
    }
}
