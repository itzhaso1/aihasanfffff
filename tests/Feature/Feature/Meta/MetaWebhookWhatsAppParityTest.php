<?php

namespace Tests\Feature\Feature\Meta;

use App\Models\Message;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Feature\Meta\Concerns\InteractsWithMetaWebhook;
use Tests\TestCase;

class MetaWebhookWhatsAppParityTest extends TestCase
{
    use InteractsWithMetaWebhook;
    use RefreshDatabase;

    public function test_meta_and_whatsapp_webhook_paths_process_identically(): void
    {
        config()->set('meta.app_secret', 'meta_parity_secret');
        config()->set('whatsapp.app_secret', 'meta_parity_secret');

        [$workspace] = $this->workspaceWithWhatsAppPhone('pnid-parity');
        $payload = $this->whatsAppInboundPayload('pnid-parity', 'wamid.parity');

        $this->postMetaWebhook($payload, 'meta_parity_secret', '/meta-webhook')->assertAccepted();
        $this->postMetaWebhook($payload, 'meta_parity_secret', '/whatsapp-webhook')->assertAccepted();

        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()->where('provider', 'whatsapp')->count());
        $this->assertSame(1, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_page_and_instagram_objects_are_acknowledged_without_business_writes(): void
    {
        config()->set('meta.app_secret', 'meta_parity_secret');

        foreach (['page', 'instagram'] as $object) {
            $payload = [
                'object' => $object,
                'entry' => [['id' => 'evt-'.$object, 'messaging' => []]],
            ];

            $this->postMetaWebhook($payload, 'meta_parity_secret')->assertAccepted();
        }

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }
}
