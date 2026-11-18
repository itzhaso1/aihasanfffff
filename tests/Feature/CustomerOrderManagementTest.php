<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Models\AiSetting;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\Inbox\InboxAgentContextBuilder;
use App\Services\AI\Inbox\Tools\CreateCustomerOrderTool;
use App\Services\Customer\CustomerOrderCaptureService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    private function createWorkspaceWithOwner(): array
    {
        $user = User::factory()->create([
            'email' => 'owner_'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    public function test_automatic_capture_service_creates_customer_and_order(): void
    {
        [$user, $workspace] = $this->createWorkspaceWithOwner();

        $catalogItem = ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عطر المسك الملكي',
            'slug' => 'royal-musk',
            'price' => '150.00',
            'currency' => 'SAR',
            'status' => 'active',
            'is_active' => true,
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        /** @var CustomerOrderCaptureService $service */
        $service = app(CustomerOrderCaptureService::class);

        $result = $service->capture(
            workspace: $workspace,
            conversation: $conversation,
            customerName: 'محمد أحمد',
            phone: '0501234567',
            items: [
                [
                    'chat_catalog_item_id' => $catalogItem->id,
                    'quantity' => 2,
                ],
            ],
            notes: 'التوصيل في المساء',
            address: 'الرياض - حي النرجس',
            isComplete: true,
        );

        $this->assertFalse($result['is_review_required']);
        $this->assertSame('+966501234567', $result['customer']->phone);
        $this->assertSame(CustomerStatus::AwaitingPayment, $result['customer']->status);
        $this->assertSame('300.00', (string) $result['order']->total_amount);
        $this->assertSame('pending', $result['order']->payment_status);
        $this->assertNull($result['order']->review_reason);
        $this->assertSame((int) $conversation->id, (int) $result['order']->conversation_id);
        $this->assertSame((int) $result['customer']->id, (int) $conversation->fresh()->customer_id);
    }

    public function test_capture_service_deduplicates_by_phone_and_flags_for_review_when_incomplete(): void
    {
        [$user, $workspace] = $this->createWorkspaceWithOwner();

        $existingCustomer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'سارة عبدالله',
            'phone' => '+966551234567',
            'status' => CustomerStatus::Completed,
            'orders_count' => 1,
            'total_purchases' => '100.00',
        ]);

        /** @var CustomerOrderCaptureService $service */
        $service = app(CustomerOrderCaptureService::class);

        $result = $service->capture(
            workspace: $workspace,
            conversation: null,
            customerName: 'سارة عبدالله',
            phone: '0551234567', // same phone in Saudi local format
            items: [
                [
                    'product_name' => 'فستان صيفي',
                    'quantity' => 1,
                    'unit_price' => '250.00',
                ],
            ],
            isComplete: false,
            reviewReason: 'المقاس المطلوب غير محدد',
            requiredAction: 'التواصل لتحديد المقاس المناسب',
        );

        $this->assertTrue($result['is_review_required']);
        $this->assertSame($existingCustomer->id, $result['customer']->id);
        $this->assertSame(CustomerStatus::NeedsReview, $result['customer']->status);
        $this->assertSame('draft', $result['order']->status);
        $this->assertSame('المقاس المطلوب غير محدد', $result['order']->review_reason);
        $this->assertSame('التواصل لتحديد المقاس المناسب', $result['order']->review_required_action);
        $this->assertSame(2, $result['customer']->fresh()->orders_count);
    }

    public function test_ai_tool_create_customer_order_executes_successfully(): void
    {
        [$user, $workspace] = $this->createWorkspaceWithOwner();

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'status' => 'open',
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'أريد طلب ساعة ذكية',
            'ai_generated' => false,
        ]);

        $setting = AiSetting::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Assistant',
            'instructions' => 'مساعد مبيعات',
            'provider' => 'google_ai_studio',
            'model' => 'gemini-2.5-flash',
            'is_active' => true,
        ]);

        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        /** @var CreateCustomerOrderTool $tool */
        $tool = app(CreateCustomerOrderTool::class);

        $result = $tool->execute($context, [
            'customer_name' => 'خالد المطيري',
            'phone' => '+966567891234',
            'items' => [
                [
                    'product_name' => 'ساعة ذكية',
                    'quantity' => 1,
                    'unit_price' => 450,
                ],
            ],
            'is_complete' => true,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('create_customer_order', $result->toolName);
        $this->assertNotEmpty($result->payload['order_number']);
        $this->assertSame('450.00', $result->payload['order_total']);
    }

    public function test_web_customers_index_and_tabs(): void
    {
        [$user, $workspace] = $this->createWorkspaceWithOwner();

        Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل مراجعة',
            'phone' => '+966511111111',
            'status' => CustomerStatus::NeedsReview,
        ]);

        Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل مكتمل',
            'phone' => '+966522222222',
            'status' => CustomerStatus::Completed,
        ]);

        // 1. Visit index with all tab
        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.customers.index', ['tab' => 'all']));

        $response->assertOk();
        $response->assertSee('إدارة العملاء ومتابعة الطلبات');
        $response->assertSee('عميل مراجعة');
        $response->assertSee('عميل مكتمل');

        // 2. Visit index with needs_review tab
        $reviewResponse = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.customers.index', ['tab' => 'needs_review']));

        $reviewResponse->assertOk();
        $reviewResponse->assertSee('طابور التدخل والمراجعة البشرية');
    }

    public function test_web_customer_show_and_status_update(): void
    {
        [$user, $workspace] = $this->createWorkspaceWithOwner();

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'فهد القحطاني',
            'phone' => '+966533333333',
            'status' => CustomerStatus::NeedsReview,
        ]);

        // Visit customer show page
        $showResponse = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.customers.show', $customer));

        $showResponse->assertOk();
        $showResponse->assertSee('فهد القحطاني');

        // Update customer status
        $updateResponse = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->patch(route('workspace.customers.status.update', $customer), [
                'status' => CustomerStatus::InProgress->value,
                'note' => 'تم الاتفاق مع العميل والبدء في التجهيز',
            ]);

        $updateResponse->assertRedirect();
        $this->assertSame(CustomerStatus::InProgress, $customer->fresh()->status);
        $this->assertDatabaseHas('customer_notes', [
            'customer_id' => $customer->id,
            'workspace_id' => $workspace->id,
        ]);
    }

    public function test_web_order_review_action_approves_order(): void
    {
        [$user, $workspace] = $this->createWorkspaceWithOwner();

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عبدالعزيز السالم',
            'phone' => '+966544444444',
            'status' => CustomerStatus::NeedsReview,
        ]);

        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-00099999',
            'status' => 'draft',
            'payment_status' => 'pending',
            'review_reason' => 'مراجعة العنوان مع العميل',
            'total_amount' => '150.00',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.customers.orders.review-action', $order), [
                'action' => 'approve',
                'note' => 'تم تأكيد العنوان هاتفياً',
            ]);

        $response->assertRedirect();
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertNull($order->fresh()->review_reason);
        $this->assertSame(CustomerStatus::AwaitingPayment, $customer->fresh()->status);
    }

    public function test_mobile_customer_stats_and_status_filter(): void
    {
        $user = User::factory()->create([
            'email' => 'mobcrm@example.com',
            'password' => 'password',
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل مراجعة 1',
            'phone' => '+966599999901',
            'status' => CustomerStatus::NeedsReview,
        ]);

        Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل مكتمل 1',
            'phone' => '+966599999902',
            'status' => CustomerStatus::Completed,
        ]);

        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $token = $login->json('data.token');

        // Test stats endpoint
        $statsResponse = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/customers/stats');

        $statsResponse->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.needs_review', 1)
            ->assertJsonPath('data.completed', 1);

        // Test status filtering
        $filterResponse = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/customers?status=needs_review');

        $filterResponse->assertOk();
        $items = $filterResponse->json('data');
        $this->assertCount(1, $items);
        $this->assertSame('needs_review', $items[0]['status']);
        $this->assertSame('يحتاج إلى مراجعة', $items[0]['status_label']);
    }
}
