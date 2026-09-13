<?php

namespace App\Services\Email;

use App\Models\EmailLog;
use App\Models\EmailWebhookEvent;
use App\Support\Webhooks\WebhookCredential;

class ResendWebhookService
{
    /**
     * Verify Resend (Svix) webhook signature.
     *
     * @param  array{svix-id?:string,svix-timestamp?:string,svix-signature?:string}  $headers
     * @return array{verified:bool,reason:?string}
     */
    public function verifySignature(array $headers, string $rawBody): array
    {
        $secret = WebhookCredential::configured(config('services.resend.webhook_secret'));
        if ($secret === null) {
            return [
                'verified' => false,
                'reason' => 'Resend webhook secret is not configured.',
            ];
        }

        $msgId = trim((string) ($headers['svix-id'] ?? ''));
        $timestamp = trim((string) ($headers['svix-timestamp'] ?? ''));
        $signatureHeader = trim((string) ($headers['svix-signature'] ?? ''));

        if ($msgId === '' || $timestamp === '' || $signatureHeader === '') {
            return [
                'verified' => false,
                'reason' => 'Missing Svix signature headers.',
            ];
        }

        if (! ctype_digit($timestamp)) {
            return [
                'verified' => false,
                'reason' => 'Invalid Svix timestamp.',
            ];
        }

        $tolerance = (int) config('services.resend.webhook_tolerance_seconds', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return [
                'verified' => false,
                'reason' => 'Svix timestamp outside tolerance window.',
            ];
        }

        $key = $this->decodeSigningKey($secret);
        if ($key === null) {
            return [
                'verified' => false,
                'reason' => 'Invalid Resend webhook secret format.',
            ];
        }

        $signedContent = $msgId.'.'.$timestamp.'.'.$rawBody;
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $key, true));

        $candidates = [];
        foreach (preg_split('/\s+/', $signatureHeader) ?: [] as $part) {
            if (str_starts_with($part, 'v1,')) {
                $candidates[] = substr($part, 3);
            } elseif ($part !== '') {
                $candidates[] = $part;
            }
        }

        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return [
                    'verified' => true,
                    'reason' => null,
                ];
            }
        }

        return [
            'verified' => false,
            'reason' => 'Svix signature mismatch.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, ?string $svixId = null): EmailWebhookEvent
    {
        $eventType = (string) ($payload['type'] ?? 'unknown');
        $providerMessageId = $this->extractProviderMessageId($payload);
        $svixId = trim((string) $svixId);

        if ($svixId !== '') {
            $replayed = EmailWebhookEvent::query()
                ->where('provider', 'resend')
                ->where('payload->svix_id', $svixId)
                ->first();
            if ($replayed) {
                return $replayed;
            }
        }

        $emailLog = null;

        if ($providerMessageId !== '') {
            $emailLog = EmailLog::withoutGlobalScopes()
                ->where('provider', 'resend')
                ->where('provider_message_id', $providerMessageId)
                ->latest('id')
                ->first();
        }

        if ($svixId !== '') {
            $payload['svix_id'] = $svixId;
        }

        $event = EmailWebhookEvent::query()->create([
            'email_log_id' => $emailLog?->id,
            'provider' => 'resend',
            'event_type' => $eventType,
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'payload' => $payload,
            'processed_at' => now(),
        ]);

        if ($emailLog) {
            $status = null;
            $error = $emailLog->error;
            if (in_array($eventType, ['email.delivered', 'delivered'], true)) {
                $status = 'sent';
                $error = null;
            }
            if (in_array($eventType, ['email.bounced', 'bounced'], true)) {
                $status = 'failed';
                $error = 'Resend webhook reported bounce.';
            }

            if ($status !== null) {
                $query = EmailLog::withoutGlobalScopes()->whereKey($emailLog->id);
                if ($emailLog->workspace_id) {
                    $query->where('workspace_id', $emailLog->workspace_id);
                } else {
                    $query->whereNull('workspace_id');
                }
                $query->update([
                    'status' => $status,
                    'error' => $error,
                    'updated_at' => now(),
                ]);
            }
        }

        return $event;
    }

    private function decodeSigningKey(string $secret): ?string
    {
        if (str_starts_with($secret, 'whsec_')) {
            $decoded = base64_decode(substr($secret, 6), true);
            if ($decoded === false || $decoded === '') {
                return null;
            }

            return $decoded;
        }

        return $secret;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractProviderMessageId(array $payload): string
    {
        $direct = $payload['message_id'] ?? null;
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            $candidate = $data['email_id'] ?? $data['id'] ?? $data['message_id'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}
