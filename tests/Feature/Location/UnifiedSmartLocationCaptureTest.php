<?php

declare(strict_types=1);

namespace Tests\Feature\Location;

use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Contracts\ModelGateway;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Core\Decision\ModelUsage;
use App\AI\Employees\EmployeeFactory;
use App\AI\Employees\EmployeeService;
use App\AI\Guard\HasimResponseGuard;
use App\AI\Inbox\HasimInboxRuntime;
use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageCreated;
use App\Jobs\ProcessAIResponse;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\LocationCaptureRevision;
use App\Models\LocationCaptureSession;
use App\Models\Message;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Models\WorkspaceTelegramConnection;
use App\Models\WorkspaceUser;
use App\Services\Customer\CustomerOrderCaptureService;
use App\Services\Location\LocationCaptureService;
use App\Services\Meta\Facebook\FacebookWebhookService;
use App\Services\Meta\Instagram\InstagramWebhookService;
use App\Services\Telegram\TelegramWebhookService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Meta\MetaConnectionKind;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnifiedSmartLocationCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'location.geocoding.enabled' => true,
            'location.geocoding.driver' => 'nominatim',
            'location.capture_token_ttl_hours' => 24,
            'location.max_updates_per_conversation_hour' => 10,
            'location.weak_accuracy_threshold_meters' => 150,
            'ai.inbox_agent.tools_enabled' => true,
        ]);
    }

    public function test_web_location_capture_lifecycle_security_single_use_and_order_linking(): void
    {
        [$workspace, $user, $customer, $conversation] = $this->createInboxFixture('whatsapp');
        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'order_number' => 'ORD-LOC-001',
            'status' => 'draft',
            'payment_status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'shipping_status' => 'not_shipped',
            'currency' => 'SAR',
            'subtotal' => 120,
            'total_amount' => 120,
            'review_reason' => 'ناقص العنوان للتوصيل',
            'review_required_action' => 'انتظار تحديد موقع العميل',
        ]);

        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'name' => 'برج المملكة',
                'display_name' => 'حي العليا، الرياض، السعودية',
                'address' => [
                    'country_code' => 'sa',
                    'country' => 'السعودية',
                    'city' => 'الرياض',
                    'suburb' => 'العليا',
                    'road' => 'طريق الملك فهد',
                    'postcode' => '12214',
                ],
            ], 200),
        ]);

        $captureService = app(LocationCaptureService::class);
        $issued = $captureService->createCaptureSession($workspace, $conversation, $order);

        $captureUrl = $issued['capture_url'];
        $path = parse_url($captureUrl, PHP_URL_PATH);
        $plainToken = basename((string) $path);

        // Plaintext token must NEVER be persisted in DB (only SHA-256 hash).
        $sessionRow = $issued['session'];
        $this->assertNotSame($plainToken, $sessionRow->token_hash);
        $this->assertSame(hash('sha256', $plainToken), $sessionRow->token_hash);
        $this->assertStringContainsString('Live Location', $issued['prompt_message']);

        // Open public Arabic RTL capture view.
        $this->get($captureUrl)
            ->assertOk()
            ->assertSee('تحديد موقع التوصيل')
            ->assertSee('تأكيد الموقع');

        // Reject unconfirmed payload without confirm=true.
        $this->postJson($captureUrl.'/confirm', [
            'latitude' => 24.711,
            'longitude' => 46.674,
            'confirm' => false,
        ])->assertStatus(422);

        // Reject out-of-range coordinates.
        $this->postJson($captureUrl.'/confirm', [
            'latitude' => 190.0,
            'longitude' => 46.674,
            'confirm' => true,
        ])->assertStatus(422);

        Queue::fake();
        Event::fake([MessageCreated::class, ConversationUpdated::class]);

        // Valid confirmed submission (with GPS + manual pin adjust & landmark note).
        $confirmResponse = $this->postJson($captureUrl.'/confirm', [
            'latitude' => 24.7112233,
            'longitude' => 46.6744556,
            'accuracy_meters' => 18,
            'source' => 'gps_adjusted',
            'confirm' => true,
            'customer_notes' => 'عمارة 12 الدور الثالث',
        ]);

        $confirmResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order_number', 'ORD-LOC-001')
            ->assertJsonPath('location.city', 'الرياض')
            ->assertJsonPath('location.verification_status', 'confirmed');

        Event::assertDispatched(MessageCreated::class);
        Event::assertDispatched(ConversationUpdated::class);
        Queue::assertPushed(ProcessAIResponse::class);

        $location = CustomerLocation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('confirmed', $location->verification_status);
        $this->assertSame('الرياض، العليا، طريق الملك فهد', $location->formatted_address);

        $orderFresh = $order->fresh();
        $this->assertSame($location->id, $orderFresh->delivery_location_id);
        $this->assertNull($orderFresh->review_reason);
        $this->assertSame('الرياض، العليا، طريق الملك فهد', $orderFresh->metadata['shipping_address']);

        $customerFresh = $customer->fresh();
        $this->assertSame($location->id, $customerFresh->primary_location_id);
        $this->assertSame('الرياض', $customerFresh->city);

        // Single-use lock check: GET shows read-only locked screen, second POST returns 409.
        $this->get($captureUrl)
            ->assertOk()
            ->assertSee('تم تأكيد الموقع مسبقاً');

        $this->postJson($captureUrl.'/confirm', [
            'latitude' => 25.0,
            'longitude' => 47.0,
            'confirm' => true,
        ])->assertStatus(409)->assertJsonPath('error_code', 'session_locked');

        // Check revision audit log exists (confirmed + linked_order).
        $revisions = LocationCaptureRevision::withoutGlobalScopes()
            ->where('customer_location_id', $location->id)
            ->pluck('event_type')
            ->all();
        $this->assertContains('confirmed', $revisions);
        $this->assertContains('linked_order', $revisions);
    }

    public function test_expired_and_unknown_capture_links_are_rejected_and_geolocation_failure_preserves_coords(): void
    {
        [$workspace, $user, $customer, $conversation] = $this->createInboxFixture('instagram');
        $service = app(LocationCaptureService::class);

        $issued = $service->createCaptureSession($workspace, $conversation);
        $this->assertFalse($issued['supports_native_location']);
        $this->assertStringNotContainsString('Live Location', $issued['prompt_message']);

        // Unknown 64-hex token returns 404.
        $this->get('/l/'.str_repeat('a', 64))->assertNotFound();

        // Expire session & verify 410 on GET and POST.
        $issued['session']->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->get($issued['capture_url'])
            ->assertStatus(410)
            ->assertSee('انتهت صلاحية الرابط');
        $this->postJson($issued['capture_url'].'/confirm', [
            'latitude' => 24.7,
            'longitude' => 46.6,
            'confirm' => true,
        ])->assertStatus(410)->assertJsonPath('error_code', 'session_expired');

        // Fresh link + reverse geocoding 500 failure must still preserve raw coordinates!
        Http::fake(['*' => Http::response([], 500)]);
        $fresh = $service->createCaptureSession($workspace, $conversation);

        $this->postJson($fresh['capture_url'].'/confirm', [
            'latitude' => 21.543333,
            'longitude' => 39.172779,
            'accuracy_meters' => 45,
            'source' => 'manual_pin',
            'confirm' => true,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('location.latitude', 21.543333);

        $loc = CustomerLocation::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('failed', $loc->geocoding_status);
        $this->assertEquals(21.543333, $loc->latitude);
        $this->assertEquals(39.172779, $loc->longitude);
    }

    public function test_direct_whatsapp_telegram_messenger_and_instagram_location_webhooks_and_dedupe(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'display_name' => 'حي النرجس، الرياض، السعودية',
                'address' => ['city' => 'الرياض', 'suburb' => 'حي النرجس', 'country_code' => 'sa'],
            ], 200),
        ]);

        [$workspace] = $this->createInboxFixture('whatsapp');

        // 1. WhatsApp native location + duplicate webhook idempotency
        $waAccount = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'waba-1',
            'display_name' => 'WA Main',
            'status' => 'connected',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $waAccount->id,
            'phone_number_id' => 'pn-1',
            'display_phone_number' => '+966500000001',
            'status' => 'connected',
        ]);

        Queue::fake();
        $waService = app(WhatsAppService::class);
        $waMsgData = [
            'from' => '966551112233',
            'id' => 'wamid.loc.wa.1',
            'type' => 'location',
            'location' => [
                'latitude' => 24.8211,
                'longitude' => 46.6311,
                'name' => 'الرياض بارك',
                'address' => 'حي العقيق',
            ],
        ];
        $waMessage = $waService->storeIncomingMessage($workspace->id, $waMsgData, 'pn-1');
        $waDup = $waService->storeIncomingMessage($workspace->id, $waMsgData, 'pn-1');
        $this->assertSame($waMessage->id, $waDup->id);
        $this->assertSame('location', $waMessage->message_type);
        $this->assertSame(1, CustomerLocation::withoutGlobalScopes()->where('external_message_id', 'wamid.loc.wa.1')->count());

        // 2. Telegram weak GPS location triggers needs_adjustment status
        $tgConnection = new WorkspaceTelegramConnection([
            'workspace_id' => $workspace->id,
            'bot_user_id' => 'bot-1',
            'bot_username' => 'hasem_test_bot',
            'public_webhook_key' => 'tg-key-loc-1',
            'integration_mode' => WorkspaceTelegramConnection::MODE_BOT,
            'status' => WorkspaceTelegramConnection::STATUS_CONNECTED,
        ]);
        $tgConnection->storeBotToken('123:abc');
        $tgConnection->save();

        $tgService = app(TelegramWebhookService::class);
        $tgService->processUpdate($tgConnection, [
            'update_id' => 9001,
            'message' => [
                'message_id' => 77,
                'from' => ['id' => 5001, 'first_name' => 'سعد'],
                'chat' => ['id' => 5001, 'type' => 'private'],
                'location' => [
                    'latitude' => 24.72,
                    'longitude' => 46.64,
                    'horizontal_accuracy' => 420,
                ],
            ],
        ], []);

        $tgService->storeIncomingMessage($workspace->id, [
            'update_id' => '9001',
            'chat_id' => '5001',
            'sender_user_id' => '5001',
            'message_id' => 'tg:msg:77',
            'text' => null,
            'message_type' => 'location',
            'attachments' => [[
                'kind' => 'location',
                'source' => 'telegram_location',
                'latitude' => 24.72,
                'longitude' => 46.64,
                'horizontal_accuracy' => 420,
            ]],
            'metadata' => [],
        ]);
        $tgLoc = CustomerLocation::withoutGlobalScopes()->where('platform', 'telegram')->firstOrFail();
        $this->assertSame('needs_adjustment', $tgLoc->verification_status);
        $this->assertSame(420, $tgLoc->accuracy_meters);

        // 3. Facebook Messenger location attachment
        WorkspaceMetaConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'kind' => MetaConnectionKind::FACEBOOK_PAGE,
            'external_id' => 'page-1',
            'status' => 'connected',
        ]);
        $fbService = app(FacebookWebhookService::class);
        $fbMsg = $fbService->storeIncomingMessage($workspace->id, [
            'sender_id' => 'psid-99',
            'recipient_id' => 'page-1',
            'message_id' => 'mid.fb.1',
            'text' => null,
            'message_type' => 'location',
            'attachments' => [[
                'type' => 'location',
                'payload' => ['coordinates' => ['lat' => 24.75, 'long' => 46.69]],
            ]],
            'metadata' => [],
        ]);
        $this->assertSame('location', $fbMsg->message_type);
        $this->assertDatabaseHas('customer_locations', [
            'workspace_id' => $workspace->id,
            'platform' => 'facebook_messenger',
            'source' => 'facebook_location',
        ]);

        // 4. Instagram location attachment (supported when payload carries coordinates)
        WorkspaceMetaConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'kind' => MetaConnectionKind::INSTAGRAM_USER,
            'external_id' => 'ig-1',
            'status' => 'connected',
        ]);
        $igService = app(InstagramWebhookService::class);
        $igMsg = $igService->storeIncomingMessage($workspace->id, [
            'sender_id' => 'igsid-88',
            'recipient_id' => 'ig-1',
            'message_id' => 'mid.ig.1',
            'text' => null,
            'message_type' => 'location',
            'attachments' => [[
                'type' => 'location',
                'payload' => ['coordinates' => ['latitude' => 24.76, 'longitude' => 46.71]],
            ]],
            'metadata' => [],
        ]);
        $this->assertSame('location', $igMsg->message_type);
        $this->assertDatabaseHas('customer_locations', [
            'workspace_id' => $workspace->id,
            'platform' => 'instagram',
            'source' => 'instagram_location',
        ]);
    }

    public function test_location_arriving_before_order_and_overwrite_and_closed_order_protection(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'display_name' => 'حي الملقا، الرياض',
                'address' => ['city' => 'الرياض', 'suburb' => 'حي الملقا'],
            ], 200),
        ]);

        [$workspace, $user, $customer, $conversation] = $this->createInboxFixture('whatsapp');
        $service = app(LocationCaptureService::class);

        // Capture BEFORE order exists
        $first = $service->createCaptureSession($workspace, $conversation);
        $this->postJson($first['capture_url'].'/confirm', [
            'latitude' => 24.7999,
            'longitude' => 46.6111,
            'accuracy_meters' => 10,
            'source' => 'gps',
            'confirm' => true,
        ])->assertOk();

        $firstLoc = CustomerLocation::withoutGlobalScopes()->latest('id')->firstOrFail();

        // Create order later via CustomerOrderCaptureService -> auto-links delivery_location_id
        $item = ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عطر فاخر',
            'slug' => 'luxury-perfume-1',
            'sku' => 'PERF-1',
            'price' => '200.00',
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $captured = app(CustomerOrderCaptureService::class)->capture(
            workspace: $workspace,
            conversation: $conversation,
            customerName: 'نواف',
            phone: '+966550001122',
            items: [['chat_catalog_item_id' => $item->id, 'product_name' => 'عطر فاخر', 'unit_price' => 200, 'quantity' => 1]],
        );

        $order = $captured['order'];
        $this->assertSame($firstLoc->id, $order->delivery_location_id);
        $this->assertSame('الرياض، حي الملقا', $order->metadata['shipping_address']);

        // Sending another different location directly via WhatsApp webhook must NOT silently overwrite confirmed order location
        $waService = app(WhatsAppService::class);
        $waService->storeIncomingMessage($workspace->id, [
            'from' => $conversation->external_id,
            'id' => 'wamid.loc.change.1',
            'type' => 'location',
            'location' => ['latitude' => 24.5100, 'longitude' => 46.9200],
        ]);

        app(WorkspaceContext::class)->runFor($workspace, fn () => $order->refresh());
        $this->assertSame($firstLoc->id, $order->delivery_location_id);
        $secondLoc = CustomerLocation::withoutGlobalScopes()->where('external_message_id', 'wamid.loc.change.1')->firstOrFail();
        $this->assertSame(CustomerLocation::STATUS_PENDING_CONFIRMATION, $secondLoc->verification_status);

        // Close/complete the order, then confirm a new capture session -> closed order stays on its historical location!
        $order->forceFill(['status' => 'completed', 'fulfillment_status' => 'fulfilled', 'shipping_status' => 'delivered'])->save();
        $third = $service->createCaptureSession($workspace, $conversation, purpose: 'update_delivery');
        $this->postJson($third['capture_url'].'/confirm', [
            'latitude' => 24.85,
            'longitude' => 46.75,
            'confirm' => true,
        ])->assertOk();

        app(WorkspaceContext::class)->runFor($workspace, fn () => $order->refresh());
        $this->assertSame($firstLoc->id, $order->delivery_location_id);
        $this->assertSame(CustomerLocation::STATUS_SUPERSEDED, $firstLoc->fresh()->verification_status);
    }

    public function test_tenant_isolation_rate_limiting_and_agent_endpoints_plus_ui_card(): void
    {
        [$wsA, $userA, $custA, $convA] = $this->createInboxFixture('whatsapp');
        [$wsB, $userB] = $this->createInboxFixture('telegram');

        $locA = CustomerLocation::withoutGlobalScopes()->create([
            'workspace_id' => $wsA->id,
            'customer_id' => $custA->id,
            'conversation_id' => $convA->id,
            'latitude' => 24.71,
            'longitude' => 46.67,
            'accuracy_meters' => 12,
            'source' => 'gps',
            'platform' => 'whatsapp',
            'verification_status' => CustomerLocation::STATUS_PENDING_CONFIRMATION,
            'formatted_address' => 'حي العليا، الرياض',
        ]);

        // Cross-workspace userB via Mobile API gets 404 on convA & locA
        Sanctum::actingAs($userB);
        $this->withHeader('X-Workspace-Id', (string) $wsB->id)
            ->postJson('/api/mobile/v1/conversations/'.$convA->id.'/location-request')
            ->assertNotFound();

        $this->withHeader('X-Workspace-Id', (string) $wsB->id)
            ->patchJson('/api/mobile/v1/locations/'.$locA->id.'/verification', ['verification_status' => 'confirmed'])
            ->assertNotFound();

        // Authorized workspace A user requests location link and verifies location via Mobile API
        Sanctum::actingAs($userA);
        $this->withHeader('X-Workspace-Id', (string) $wsA->id)
            ->postJson('/api/mobile/v1/conversations/'.$convA->id.'/location-request')
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['capture_url', 'expires_at', 'message']]);

        $this->withHeader('X-Workspace-Id', (string) $wsA->id)
            ->patchJson('/api/mobile/v1/locations/'.$locA->id.'/verification', ['verification_status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.location.verification_status', 'confirmed');

        // Web inbox view renders rich location card + location request button
        Message::withoutGlobalScopes()->create([
            'workspace_id' => $wsA->id,
            'conversation_id' => $convA->id,
            'customer_id' => $custA->id,
            'direction' => 'inbound',
            'message_type' => 'location',
            'content' => '📍 موقع',
            'metadata' => ['location' => $locA->fresh()->toSummaryArray()],
        ]);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $wsA->id])
            ->get(route('workspace.conversations.index', ['conversation' => $convA->id]))
            ->assertOk()
            ->assertSee('موقع التوصيل')
            ->assertSee('حي العليا، الرياض')
            ->assertSee('فتح في الخريطة')
            ->assertSee('طلب الموقع');
    }

    public function test_ai_runtime_can_call_request_location_capture_tool_and_pass_guard(): void
    {
        [$workspace, $user, $customer, $conversation] = $this->createInboxFixture('whatsapp');

        app(EmployeeService::class)->create(
            $workspace,
            app(EmployeeFactory::class)->fromPreset('sales_saudi'),
            $user->id,
        );

        $msg = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'أبغى أطلب توصيل للبيت، أين أرسل موقعي؟',
        ]);

        $gateway = new class implements ModelGateway
        {
            private int $calls = 0;

            public function generate(ModelRequest $request): ModelResponse
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return new ModelResponse(
                        text: '',
                        toolCalls: [new ModelToolCall('call-loc-1', 'request_location_capture', ['purpose' => 'delivery'])],
                        finishReason: ModelFinishReason::ToolCalls,
                        usage: new ModelUsage('fake', 'fake-1', 10, 10),
                    );
                }

                $url = '';
                foreach ($request->toolExchanges as $ex) {
                    if ($ex->call->name === 'request_location_capture') {
                        $url = (string) ($ex->result['capture_url'] ?? '');
                    }
                }

                return new ModelResponse(
                    text: "تفضل رابط تحديد موقع التوصيل بدقة:\n".$url."\nأو شارك موقعك مباشرة هنا.",
                    toolCalls: [],
                    finishReason: ModelFinishReason::Stop,
                    usage: new ModelUsage('fake', 'fake-1', 10, 12),
                );
            }
        };

        $this->app->instance(ModelGateway::class, $gateway);

        $runtime = HasimInboxRuntime::fromApplication($this->app);
        $result = $runtime->reply($conversation, $msg);

        $this->assertTrue($result->deliverable);
        $this->assertStringContainsString('/l/', $result->reply);
        $this->assertCount(1, LocationCaptureSession::withoutGlobalScopes()->where('conversation_id', $conversation->id)->get());

        // Guard keeps URL intact cleanly.
        $guardOutcome = (new HasimResponseGuard)->review(
            new GuardInput(
                request: new AiRequest(
                    workspaceId: $workspace->id,
                    conversationId: $conversation->id,
                    employeeId: null,
                    message: new AiInboundMessage('test'),
                    runMode: AiRunMode::Live,
                    channel: 'whatsapp',
                ),
                text: $result->reply,
                finishReason: ModelFinishReason::Stop,
            ),
        );
        $this->assertNotSame(GuardVerdict::Rejected, $guardOutcome->verdict);
    }

    /**
     * @return array{0: Workspace, 1: User, 2: Customer, 3: Conversation}
     */
    private function createInboxFixture(string $channel): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => 'متجر تجريبي',
        ]);
        WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()->firstOrCreate(
            ['code' => 'pro'],
            [
                'name' => 'Pro',
                'workspace_type' => 'company',
                'billing_period' => 'monthly',
                'currency' => 'USD',
                'price' => 99,
                'is_active' => true,
                'features' => ['ai' => true, 'orders' => true, 'whatsapp' => true],
                'limits' => ['ai_usage' => 10000, 'ai_tokens' => 1000000],
            ],
        );
        Subscription::query()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل تجريبي',
            'phone' => '+96650'.random_int(1000000, 9999999),
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => $channel,
            'external_id' => 'ext-'.uniqid(),
            'status' => 'open',
            'ai_enabled' => true,
            'metadata' => ['channel_source' => $channel],
        ]);

        return [$workspace, $user, $customer, $conversation];
    }
}
