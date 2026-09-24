<?php

namespace Tests\Feature\Feature\Meta;

use App\Models\Message;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Feature\Meta\Concerns\InteractsWithMetaWebhook;
use Tests\TestCase;

class MetaWebhookTenantIsolationTest extends TestCase
{
    use InteractsWithMetaWebhook;
    use RefreshDatabase;

    public function test_unknown_phone_number_does_not_create_webhook_event_or_message(): void
    {
        config()->set('meta.app_secret', 'meta_tenant_secret');
        [$workspace] = $this->workspaceWithWhatsAppPhone('pnid-known');

        $unknown = $this->whatsAppInboundPayload('pnid-unknown-meta', 'wamid.unknown');
        $this->postMetaWebhook($unknown, 'meta_tenant_secret')->assertAccepted();

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        $this->assertSame(0, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_replay_is_idempotent_on_meta_webhook(): void
    {
        config()->set('meta.app_secret', 'meta_tenant_secret');
        [$workspace] = $this->workspaceWithWhatsAppPhone('pnid-replay');
        $payload = $this->whatsAppInboundPayload('pnid-replay', 'wamid.replay');

        $this->postMetaWebhook($payload, 'meta_tenant_secret')->assertAccepted();
        $this->postMetaWebhook($payload, 'meta_tenant_secret')->assertAccepted();

        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()->where('provider', 'whatsapp')->count());
        $this->assertSame(1, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }
}
