<?php

namespace Tests\Feature\Feature\Channel;

use App\Jobs\Media\ProcessInboundMediaAttachment;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerChannelIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Services\Customer\CustomerChannelIdentityService;
use App\Services\Media\InboundMediaCoordinator;
use App\Services\Media\MediaSecurityValidator;
use App\Services\Media\MediaStorageService;
use App\Services\Media\Provider\FacebookProviderMediaClient;
use App\Services\Media\Provider\InstagramProviderMediaClient;
use App\Services\Media\Provider\TelegramProviderMediaClient;
use App\Services\Media\Provider\WhatsAppProviderMediaClient;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Meta\MetaConnectionKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChannelMediaPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_image_inbound_persists_message_and_queues_media_job_without_blocking(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $messageData = [
            'from' => '966511111111',
            'id' => 'wamid.media.1',
            'type' => 'image',
            'image' => [
                'id' => 'wa_media_123',
                'mime_type' => 'image/jpeg',
                'caption' => 'Photo caption',
            ],
        ];

        $started = microtime(true);
        $message = app(WhatsAppService::class)->storeIncomingMessage($workspace->id, $messageData, 'pnid_1');
        $persistMs = (int) round((microtime(true) - $started) * 1000);

        $this->assertSame('image', $message->message_type);
        $this->assertSame('Photo caption', $message->content);
        $this->assertDatabaseHas('message_attachments', [
            'workspace_id' => $workspace->id,
            'message_id' => $message->id,
            'provider' => 'whatsapp',
            'provider_media_id' => 'wa_media_123',
            'status' => MessageAttachment::STATUS_PENDING,
        ]);

        Queue::assertPushed(ProcessInboundMediaAttachment::class);
        $this->assertLessThan(500, $persistMs);
    }

    public function test_whatsapp_webhook_processes_multiple_messages(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        WhatsAppPhoneNumber::query()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => WhatsAppAccount::query()->create([
                'workspace_id' => $workspace->id,
                'business_account_id' => 'waba',
                'display_name' => 'WA',
                'status' => 'connected',
                'metadata' => [],
            ])->id,
            'phone_number_id' => 'pn_multi',
            'display_phone_number' => '+966500000000',
            'verified_name' => 'Test',
            'status' => 'connected',
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'pn_multi'],
                        'messages' => [
                            ['from' => '966511111111', 'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'one']],
                            ['from' => '966522222222', 'id' => 'wamid.2', 'type' => 'text', 'text' => ['body' => 'two']],
                        ],
                    ],
                ]],
            ]],
        ];

        app(WhatsAppService::class)->processWebhook($payload, []);

        Queue::assertPushed(ProcessIncomingWhatsAppMessage::class, 2);
    }

    public function test_duplicate_provider_media_is_idempotent(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => Conversation::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'channel' => 'instagram',
                'external_id' => 'ig:1',
                'status' => 'open',
            ])->id,
            'direction' => 'inbound',
            'message_type' => 'image',
            'content' => null,
        ]);

        $coordinator = app(InboundMediaCoordinator::class);
        $descriptor = [
            'kind' => 'image',
            'provider_media_id' => 'dup_media',
            'download_url' => 'https://lookaside.fbsbx.com/example',
        ];

        $coordinator->registerFromDescriptors($message, 'instagram', [$descriptor]);
        $coordinator->registerFromDescriptors($message, 'instagram', [$descriptor]);

        $this->assertSame(1, MessageAttachment::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('provider', 'instagram')
            ->where('provider_media_id', 'dup_media')
            ->count());

        Queue::assertPushed(ProcessInboundMediaAttachment::class, 1);
    }

    public function test_media_security_rejects_unsafe_extension(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(MediaSecurityValidator::class)->assertSafeFilename('malware.exe');
    }

    public function test_customer_channel_identity_unique_per_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $customerA = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'A',
            'phone' => '966511111111',
        ]);
        $customerB = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'B',
            'phone' => '966522222222',
        ]);

        $service = app(CustomerChannelIdentityService::class);
        $service->ensureIdentity($workspace->id, $customerA->id, 'instagram', 'igsid_1');
        $existing = $service->ensureIdentity($workspace->id, $customerB->id, 'instagram', 'igsid_1');

        $this->assertSame($customerA->id, $existing->customer_id);
        $this->assertSame(1, CustomerChannelIdentity::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_instagram_inbound_media_download_stores_attachment(): void
    {
        [$workspace] = $this->workspaceWithInstagram('ig_media');

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'external_id' => 'ig:+user',
            'status' => 'open',
            'metadata' => [
                'instagram_account_id' => 'ig_media',
                'instagram_user_id' => 'igsid_media',
            ],
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'message_type' => 'image',
            'content' => null,
        ]);

        $attachment = MessageAttachment::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'message_id' => $message->id,
            'provider' => 'instagram',
            'provider_media_id' => 'hash123',
            'disk' => '',
            'path' => '',
            'original_name' => 'image',
            'kind' => 'image',
            'status' => MessageAttachment::STATUS_PENDING,
            'provider_metadata' => [
                'download_url' => 'https://lookaside.fbsbx.com/igm/image',
            ],
        ]);

        Http::fake([
            'lookaside.fbsbx.com/*' => Http::response("\xFF\xD8\xFFfakejpeg", 200, ['Content-Type' => 'image/jpeg']),
        ]);

        (new ProcessInboundMediaAttachment($attachment->id, $workspace->id))->handle(
            app(MediaStorageService::class),
            app(WhatsAppProviderMediaClient::class),
            app(InstagramProviderMediaClient::class),
            app(TelegramProviderMediaClient::class),
            app(FacebookProviderMediaClient::class),
        );

        $attachment->refresh();
        $this->assertSame(MessageAttachment::STATUS_STORED, $attachment->status);
        $this->assertNotSame('', $attachment->path);
    }

    /**
     * @return array{0: Workspace}
     */
    private function workspaceWithInstagram(string $accountId): array
    {
        $workspace = Workspace::factory()->create();
        $connection = WorkspaceMetaConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'kind' => MetaConnectionKind::INSTAGRAM_USER,
            'external_id' => $accountId,
            'display_name' => '@media.test',
            'status' => 'connected',
            'scopes' => ['instagram_business_manage_messages'],
            'metadata' => ['graph_api_version' => 'v20.0'],
            'connected_by_user_id' => User::factory()->create()->id,
        ]);
        $connection->storeAccessToken('instagram-user-access-token');
        $connection->save();

        return [$workspace];
    }
}
