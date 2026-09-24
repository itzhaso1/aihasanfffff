<?php

namespace Tests\Feature\Feature\Meta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Feature\Meta\Concerns\InteractsWithMetaWebhook;
use Tests\TestCase;

class MetaWebhookCredentialSourceTest extends TestCase
{
    use InteractsWithMetaWebhook;
    use RefreshDatabase;

    public function test_whatsapp_get_uses_whatsapp_verify_token_when_meta_differs(): void
    {
        config()->set('meta.webhook_verify_token', 'meta-verify-token');
        config()->set('whatsapp.verify_token', 'wa-verify-token');

        $this->get('/whatsapp-webhook?hub.mode=subscribe&hub.verify_token=wa-verify-token&hub.challenge=wa-challenge')
            ->assertOk()
            ->assertSee('wa-challenge');

        $this->get('/whatsapp-webhook?hub.mode=subscribe&hub.verify_token=meta-verify-token&hub.challenge=meta-challenge')
            ->assertForbidden();
    }

    public function test_meta_get_uses_meta_verify_token_when_whatsapp_differs(): void
    {
        config()->set('meta.webhook_verify_token', 'meta-verify-token');
        config()->set('whatsapp.verify_token', 'wa-verify-token');

        $this->get('/meta-webhook?hub.mode=subscribe&hub.verify_token=meta-verify-token&hub.challenge=meta-challenge')
            ->assertOk()
            ->assertSee('meta-challenge');

        $this->get('/meta-webhook?hub.mode=subscribe&hub.verify_token=wa-verify-token&hub.challenge=wa-challenge')
            ->assertForbidden();
    }

    public function test_whatsapp_post_uses_whatsapp_app_secret_when_meta_differs(): void
    {
        config()->set('meta.app_secret', 'meta-app-secret');
        config()->set('whatsapp.app_secret', 'wa-app-secret');

        $payload = ['entry' => []];

        $this->postMetaWebhook($payload, 'wa-app-secret', '/whatsapp-webhook')->assertAccepted();
        $this->postMetaWebhook($payload, 'meta-app-secret', '/whatsapp-webhook')->assertForbidden();
    }

    public function test_meta_post_uses_meta_app_secret_when_whatsapp_differs(): void
    {
        config()->set('meta.app_secret', 'meta-app-secret');
        config()->set('whatsapp.app_secret', 'wa-app-secret');

        $payload = ['entry' => []];

        $this->postMetaWebhook($payload, 'meta-app-secret', '/meta-webhook')->assertAccepted();
        $this->postMetaWebhook($payload, 'wa-app-secret', '/meta-webhook')->assertForbidden();
    }
}
