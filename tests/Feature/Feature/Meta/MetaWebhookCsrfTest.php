<?php

namespace Tests\Feature\Feature\Meta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Feature\Meta\Concerns\InteractsWithMetaWebhook;
use Tests\TestCase;

class MetaWebhookCsrfTest extends TestCase
{
    use InteractsWithMetaWebhook;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.app_secret', 'meta_csrf_test_secret');
    }

    public function test_meta_webhook_post_is_not_blocked_by_csrf_when_signature_is_valid(): void
    {
        $payload = ['object' => 'instagram', 'entry' => []];

        $response = $this->postMetaWebhook($payload, 'meta_csrf_test_secret');

        $response->assertAccepted();
        $this->assertNotSame(419, $response->getStatusCode());
    }

    public function test_meta_webhook_post_without_csrf_token_does_not_return_419(): void
    {
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => []], JSON_UNESCAPED_UNICODE) ?: '{}';

        $response = $this->call('POST', '/meta-webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta_csrf_test_secret'),
        ], $raw);

        $this->assertNotSame(419, $response->getStatusCode());
    }
}
