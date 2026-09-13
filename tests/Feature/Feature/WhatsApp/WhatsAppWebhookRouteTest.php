<?php

namespace Tests\Feature\Feature\WhatsApp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppWebhookRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_webhook_verification_is_served_from_web_route(): void
    {
        config()->set('whatsapp.verify_token', 'verify_token_123');

        $this->get('/whatsapp-webhook?hub.mode=subscribe&hub.verify_token=verify_token_123&hub.challenge=ok123')
            ->assertOk()
            ->assertSee('ok123');

        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify_token_123&hub.challenge=old')
            ->assertNotFound();
    }

    public function test_whatsapp_webhook_post_requires_valid_signature_and_accepts_valid_one(): void
    {
        config()->set('whatsapp.app_secret', 'meta_secret_test');

        $payload = [
            'entry' => [],
        ];

        $this->postJson('/whatsapp-webhook', $payload)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Invalid signature');

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $signature = 'sha256='.hash_hmac('sha256', $encodedPayload ?: '{}', 'meta_secret_test');

        $this->withHeader('X-Hub-Signature-256', $signature)
            ->postJson('/whatsapp-webhook', $payload)
            ->assertStatus(202)
            ->assertJsonPath('received', true);
    }
}
