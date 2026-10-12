<?php

namespace Tests\Feature\Feature\Meta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Feature\Meta\Concerns\InteractsWithMetaWebhook;
use Tests\TestCase;

class MetaWebhookSignatureTest extends TestCase
{
    use InteractsWithMetaWebhook;
    use RefreshDatabase;

    public function test_meta_webhook_verify_fails_closed_when_token_missing_or_invalid(): void
    {
        foreach ([null, '', '   '] as $token) {
            config()->set('meta.webhook_verify_token', $token);
            config()->set('whatsapp.verify_token', $token);

            $this->get('/meta-webhook?hub.mode=subscribe&hub.verify_token=&hub.challenge=leak')
                ->assertForbidden();
        }

        config()->set('meta.webhook_verify_token', 'meta-verify-token');
        $this->get('/meta-webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=nope')
            ->assertForbidden();
        $this->get('/meta-webhook?hub.mode=subscribe&hub.verify_token=meta-verify-token&hub.challenge=ok-challenge')
            ->assertOk()
            ->assertSee('ok-challenge');
    }

    public function test_meta_webhook_post_requires_valid_signature(): void
    {
        config()->set('meta.app_secret', 'meta_secret_test');
        $payload = $this->whatsAppInboundPayload('pnid-sig', 'wamid.sig');

        $this->postJson('/meta-webhook', $payload)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Invalid signature');

        $this->postMetaWebhook($payload, 'meta_secret_test')
            ->assertAccepted()
            ->assertJsonPath('received', true);
    }
}
