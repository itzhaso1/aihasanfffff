<?php

namespace App\Services\Payment\Providers;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Support\Webhooks\WebhookCredential;
use Illuminate\Support\Str;

class LocalPaymentGateway implements PaymentGatewayInterface
{
    public function createPaymentLink(string $reference, float $amount, string $currency, array $metadata = []): array
    {
        $paymentId = 'local_'.Str::lower(Str::random(24));
        $token = Str::random(48);

        return [
            'provider_payment_id' => $paymentId,
            'payment_link' => url('/pay/local/'.$token),
            'payload' => [
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => $metadata,
            ],
        ];
    }

    public function verifyWebhook(array $headers, array $payload, ?string $rawBody = null): array
    {
        $secret = WebhookCredential::configured(config('payment.providers.local.webhook_secret'));
        $eventId = (string) ($payload['event_id'] ?? Str::uuid()->toString());

        // Never treat unsigned local webhooks as verified — empty secret is a misconfiguration.
        if ($secret === null) {
            return [
                'verified' => false,
                'event_id' => $eventId,
                'status' => $payload['status'] ?? null,
                'reference' => $payload['reference'] ?? null,
                'payload' => $payload,
                'reason' => 'Local payment webhook secret is not configured.',
            ];
        }

        $timestamp = (int) ($headers['x-webhook-timestamp'] ?? $headers['X-Webhook-Timestamp'] ?? 0);
        $signature = (string) ($headers['x-webhook-signature'] ?? $headers['X-Webhook-Signature'] ?? '');
        $tolerance = (int) config('payment.providers.local.webhook_tolerance_seconds', 300);

        if ($timestamp <= 0 || abs(time() - $timestamp) > $tolerance) {
            return [
                'verified' => false,
                'event_id' => $eventId,
                'status' => $payload['status'] ?? null,
                'reference' => $payload['reference'] ?? null,
                'payload' => $payload,
                'reason' => 'Webhook timestamp is outside allowed tolerance window.',
            ];
        }

        $rawBody = $rawBody ?? json_encode($payload);
        if (! is_string($rawBody)) {
            $rawBody = '';
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        if (! hash_equals($expected, $signature)) {
            return [
                'verified' => false,
                'event_id' => $eventId,
                'status' => $payload['status'] ?? null,
                'reference' => $payload['reference'] ?? null,
                'payload' => $payload,
                'reason' => 'Webhook signature mismatch.',
            ];
        }

        return [
            'verified' => true,
            'event_id' => $eventId,
            'status' => $payload['status'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'amount' => array_key_exists('amount', $payload) ? (float) $payload['amount'] : null,
            'currency' => isset($payload['currency']) ? strtoupper((string) $payload['currency']) : null,
            'payload' => $payload,
            'reason' => null,
        ];
    }
}
