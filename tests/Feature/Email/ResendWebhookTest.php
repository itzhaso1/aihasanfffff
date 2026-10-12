<?php

namespace Tests\Feature\Email;

use App\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResendWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_resend_webhook_endpoint_records_event_and_updates_log_status(): void
    {
        $secret = 'whsec_'.base64_encode('resend_test_signing_key_123');
        config()->set('services.resend.webhook_secret', $secret);
        config()->set('services.resend.webhook_tolerance_seconds', 300);

        $log = EmailLog::query()->create([
            'provider' => 'resend',
            'template' => 'general_notification',
            'recipient' => 'webhook@example.com',
            'subject' => 'Webhook Subject',
            'status' => 'pending',
            'provider_message_id' => 'resend-msg-123',
        ]);

        $payload = [
            'type' => 'email.delivered',
            'data' => [
                'email_id' => 'resend-msg-123',
            ],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers = $this->svixHeaders($secret, $rawBody);

        $response = $this->call(
            'POST',
            route('webhooks.resend'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_SVIX_ID' => $headers['svix-id'],
                'HTTP_SVIX_TIMESTAMP' => $headers['svix-timestamp'],
                'HTTP_SVIX_SIGNATURE' => $headers['svix-signature'],
            ],
            $rawBody,
        );

        $response->assertAccepted()
            ->assertJson([
                'ok' => true,
            ]);

        $this->assertDatabaseHas('email_webhook_events', [
            'provider' => 'resend',
            'event_type' => 'email.delivered',
            'provider_message_id' => 'resend-msg-123',
            'email_log_id' => $log->id,
        ]);

        $this->assertDatabaseHas('email_logs', [
            'id' => $log->id,
            'status' => 'sent',
        ]);
    }

    public function test_resend_webhook_rejects_missing_signature(): void
    {
        config()->set('services.resend.webhook_secret', 'whsec_'.base64_encode('resend_test_signing_key_123'));

        $this->postJson(route('webhooks.resend'), [
            'type' => 'email.delivered',
            'data' => ['email_id' => 'x'],
        ])->assertUnauthorized();
    }

    /**
     * @return array{svix-id:string,svix-timestamp:string,svix-signature:string}
     */
    private function svixHeaders(string $secret, string $rawBody): array
    {
        $msgId = 'msg_test_'.uniqid();
        $timestamp = (string) time();
        $key = str_starts_with($secret, 'whsec_')
            ? base64_decode(substr($secret, 6), true)
            : $secret;
        $signed = base64_encode(hash_hmac('sha256', $msgId.'.'.$timestamp.'.'.$rawBody, $key, true));

        return [
            'svix-id' => $msgId,
            'svix-timestamp' => $timestamp,
            'svix-signature' => 'v1,'.$signed,
        ];
    }
}
