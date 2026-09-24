<?php

namespace Tests\Unit\Services\Meta;

use App\Services\Meta\Instagram\InstagramWebhookService;
use App\Services\Meta\MetaWebhookRouter;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class MetaWebhookRouterLoggingTest extends TestCase
{
    public function test_instagram_dispatch_logs_metadata_only_at_info_level(): void
    {
        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'ig-entry-123',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'sender' => ['id' => 'sender-123'],
                        'message' => ['text' => 'Sensitive customer message body'],
                    ],
                ]],
                'messaging' => [[
                    'sender' => ['id' => 'sender-456'],
                    'message' => ['mid' => 'm_secret_mid', 'text' => 'Sensitive customer message body'],
                ]],
            ]],
        ];

        Log::shouldReceive('info')
            ->once()
            ->with('meta.webhook.instagram.received', Mockery::on(function (array $context): bool {
                $this->assertSame('meta', $context['provider']);
                $this->assertSame('instagram', $context['object']);
                $this->assertSame('accepted', $context['status']);
                $this->assertSame(1, $context['entry_count']);
                $this->assertSame(1, $context['change_count']);
                $this->assertSame(['messages'], $context['change_fields']);
                $this->assertSame(1, $context['messaging_count']);
                $this->assertArrayNotHasKey('payload', $context);
                $this->assertArrayNotHasKey('headers', $context);

                return true;
            }));

        Log::shouldReceive('debug')->never();

        $instagramWebhookService = Mockery::mock(InstagramWebhookService::class);
        $instagramWebhookService->shouldReceive('processWebhook')->once()->with($payload, Mockery::type('array'));

        $router = new MetaWebhookRouter(Mockery::mock(WhatsAppService::class), $instagramWebhookService);
        $router->dispatch($payload, [
            'x-hub-signature-256' => 'sha256=secret',
            'x-forwarded-for' => '203.0.113.50',
        ]);
    }

    public function test_instagram_dispatch_info_log_does_not_include_pii_or_secrets(): void
    {
        $sensitiveValues = [
            'Sensitive customer message body',
            'sender-123',
            'sender-456',
            'm_secret_mid',
            'super-secret-instagram-token',
            '966500009999',
            'customer@example.com',
            'sha256=secret',
            '203.0.113.50',
        ];

        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'ig-entry-123',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'from' => '966500009999',
                        'email' => 'customer@example.com',
                        'access_token' => 'super-secret-instagram-token',
                        'message' => ['text' => 'Sensitive customer message body'],
                    ],
                ]],
            ]],
        ];

        Log::shouldReceive('info')
            ->once()
            ->with('meta.webhook.instagram.received', Mockery::on(function (array $context) use ($sensitiveValues): bool {
                $encoded = json_encode($context, JSON_UNESCAPED_UNICODE) ?: '';

                foreach ($sensitiveValues as $value) {
                    $this->assertStringNotContainsString($value, $encoded);
                }

                return true;
            }));

        Log::shouldReceive('debug')->never();

        $instagramWebhookService = Mockery::mock(InstagramWebhookService::class);
        $instagramWebhookService->shouldReceive('processWebhook')->once();

        $router = new MetaWebhookRouter(Mockery::mock(WhatsAppService::class), $instagramWebhookService);
        $router->dispatch($payload, [
            'x-hub-signature-256' => 'sha256=secret',
            'x-forwarded-for' => '203.0.113.50',
        ]);
    }

    public function test_whatsapp_dispatch_does_not_log_payload_at_info_level(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'pnid-logging'],
                        'messages' => [[
                            'id' => 'wamid.logging',
                            'from' => '966500009007',
                            'text' => ['body' => 'hello-meta-webhook'],
                        ]],
                    ],
                ]],
            ]],
        ];

        Log::shouldReceive('info')->never();

        $whatsAppService = Mockery::mock(WhatsAppService::class);
        $whatsAppService->shouldReceive('processWebhook')
            ->once()
            ->with($payload, Mockery::type('array'));

        $router = new MetaWebhookRouter($whatsAppService, Mockery::mock(InstagramWebhookService::class));
        $router->dispatch($payload, []);
    }
}
