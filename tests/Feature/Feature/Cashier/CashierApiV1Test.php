<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\Order;
use App\Models\PosMenuItem;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierApiV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_login_bootstrap_catalog_and_takeaway_order(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = $this->makeItem($workspace, 12.5);

        $login = $this->postJson('/api/cashier/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'password',
            'device_name' => 'كاشير حاسم test',
            'device_type' => 'cashier',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) $login->json('data.pos_enabled'));
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        $bootstrap = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.app.name', 'كاشير حاسم');

        $this->assertTrue((bool) $bootstrap->json('data.pos_enabled'));
        $permissions = $bootstrap->json('data.permissions') ?? [];
        $this->assertTrue((bool) ($permissions['orders.manage'] ?? false));
        $this->assertTrue((bool) ($permissions['menu.manage'] ?? false));

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/catalog/items')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $item->id)
            ->assertJsonPath('data.items.0.price', 12.5);

        $clientRef = 'cashier-test-'.uniqid();

        $created = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'Idempotency-Key' => $clientRef,
            ])
            ->postJson('/api/cashier/v1/orders', [
                'order_type' => 'takeaway',
                'client_reference' => $clientRef,
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_type', 'takeaway')
            ->assertJsonPath('message', 'تم إنشاء الطلب بنجاح.');

        $this->assertNotNull($created->json('data.placed_at'));
        $this->assertNotNull($created->json('data.created_at'));

        $order = Order::query()->where('client_reference', $clientRef)->firstOrFail();
        $this->assertNull($order->table_session_id);
        $this->assertEquals(25.0, (float) $order->subtotal);

        $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'Idempotency-Key' => $clientRef.'-http',
            ])
            ->postJson('/api/cashier/v1/orders', [
                'order_type' => 'takeaway',
                'client_reference' => $clientRef,
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 2],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);

        $this->assertSame(1, Order::query()->where('client_reference', $clientRef)->count());

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonStructure(['data' => ['placed_at', 'created_at', 'items']]);
    }

    public function test_cashier_kitchen_reports_table_store_and_me_permissions(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $login = $this->postJson('/api/cashier/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'password',
            'device_name' => 'كاشير حاسم test',
            'device_type' => 'cashier',
        ])->assertOk();

        $token = $login->json('data.token');
        $headers = ['X-Workspace-Id' => (string) $workspace->id];

        $me = $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/auth/me')
            ->assertOk()
            ->json('data');

        $permissions = $me['permissions'] ?? [];
        $this->assertTrue((bool) ($permissions['orders.manage'] ?? false));
        $this->assertTrue((bool) ($me['pos_enabled'] ?? false));

        $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/kitchen/orders')
            ->assertOk()
            ->assertJsonStructure(['data' => ['orders', 'statuses']]);

        $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/reports/daily')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'summary' => [
                    'invoices_count',
                    'orders_count',
                    'paid_orders_count',
                    'unpaid_orders_count',
                ],
                'top_items',
                'quantity_by_type',
                'channel_stats',
                'date',
            ]]);

        $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/cashier/v1/tables', ['name' => 'طاولة اختبار API'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'طاولة اختبار API');

        $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/orders/channel-stats')
            ->assertOk()
            ->assertJsonStructure(['data' => ['stats']]);

        $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/orders/recent-menu?after_id=0')
            ->assertOk()
            ->assertJsonStructure(['data' => ['orders', 'latest_id']]);

        $bootstrapSettings = $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/bootstrap')
            ->assertOk()
            ->json('data.settings');
        $this->assertArrayHasKey('sound_enabled', $bootstrapSettings);
        $this->assertArrayHasKey('enable_delivery', $bootstrapSettings);
    }

    public function test_cashier_can_edit_and_delete_table_order(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = $this->makeItem($workspace, 10);
        $item2 = $this->makeItem($workspace, 7.5);

        $login = $this->postJson('/api/cashier/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'password',
            'device_name' => 'كاشير حاسم test',
            'device_type' => 'cashier',
        ])->assertOk();

        $token = $login->json('data.token');
        $headers = [
            'X-Workspace-Id' => (string) $workspace->id,
            'Idempotency-Key' => 'edit-del-'.uniqid(),
        ];

        $table = \App\Models\DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة تعديل '.uniqid(),
            'status' => 'available',
            'qr_token' => \Illuminate\Support\Str::random(48),
        ]);

        $create = $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/cashier/v1/orders', [
                'order_type' => 'table',
                'dining_table_id' => $table->id,
                'client_reference' => 'edit-order-'.uniqid(),
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated();

        $orderId = (int) $create->json('data.id');
        $lineId = (int) $create->json('data.items.0.id');
        $this->assertGreaterThan(0, $lineId);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson("/api/cashier/v1/orders/{$orderId}/items", [
                'notes' => 'ملاحظة تعديل',
                'discount_amount' => 1,
                'items' => [
                    [
                        'id' => $lineId,
                        'quantity' => 3,
                        'unit_price' => 10,
                    ],
                    [
                        'pos_menu_item_id' => $item2->id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'ملاحظة تعديل')
            ->assertJsonPath('data.discount_amount', 1);

        $order = Order::query()->findOrFail($orderId);
        $this->assertEquals(2, $order->items()->count());
        $this->assertEquals(37.5, (float) $order->subtotal); // 3*10 + 7.5
        $this->assertEquals('ملاحظة تعديل', $order->notes);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->deleteJson("/api/cashier/v1/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.pos_status', 'cancelled');

        $this->assertEquals('cancelled', $order->fresh()->pos_status);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/reports/daily')
            ->assertOk()
            ->assertJsonStructure(['data' => ['summary' => [
                'open_orders_count',
                'completed_orders_count',
                'cancelled_orders_count',
                'discount_total',
                'tax_total',
            ], 'payment_methods']]);
    }

    public function test_cashier_close_table_accepts_payment_method(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = $this->makeItem($workspace, 15);

        $login = $this->postJson('/api/cashier/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'password',
            'device_name' => 'كاشير حاسم test',
            'device_type' => 'cashier',
        ])->assertOk();
        $token = $login->json('data.token');
        $headers = ['X-Workspace-Id' => (string) $workspace->id];

        $table = \App\Models\DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة إغلاق '.uniqid(),
            'status' => 'available',
            'qr_token' => \Illuminate\Support\Str::random(48),
        ]);

        $create = $this->withToken($token)
            ->withHeaders($headers + ['Idempotency-Key' => 'close-'.uniqid()])
            ->postJson('/api/cashier/v1/orders', [
                'order_type' => 'table',
                'dining_table_id' => $table->id,
                'client_reference' => 'close-order-'.uniqid(),
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $sessionId = (int) $create->json('data.table_session_id');
        $this->assertGreaterThan(0, $sessionId);

        $close = $this->withToken($token)
            ->withHeaders($headers)
            ->postJson("/api/cashier/v1/tables/{$table->id}/sessions/{$sessionId}/close", [
                'payment_method' => 'cash',
            ])
            ->assertOk();

        $this->assertNotNull($close->json('data.invoice.id'));
        $this->assertSame('cash', $close->json('data.invoice.payment_method'));
        $order = Order::query()->findOrFail((int) $create->json('data.id'));
        $this->assertEquals('completed', $order->pos_status);
        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals('cash', data_get($order->metadata, 'payment_method'));
    }

    public function test_cashier_rejects_workspace_without_pos_feature(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        \App\Models\WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'pos'],
            ['workspace_id' => $workspace->id, 'feature_key' => 'pos', 'enabled' => false, 'source' => 'manual']
        );

        $tokenResult = $owner->createToken('cashier', ['*']);
        $tokenResult->accessToken->forceFill(['workspace_id' => $workspace->id])->save();
        $token = $tokenResult->plainTextToken;

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/catalog/items')
            ->assertStatus(403)
            ->assertJsonPath('message', 'الكاشير غير متاح في باقتك الحالية');
    }

    public function test_cashier_catalog_settings_reports_and_table_sessions(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $login = $this->postJson('/api/cashier/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'password',
            'device_name' => 'كاشير حاسم test',
            'device_type' => 'cashier',
        ])->assertOk();

        $token = $login->json('data.token');
        $headers = ['X-Workspace-Id' => (string) $workspace->id];

        $bootstrap = $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/bootstrap')
            ->assertOk();
        $permissions = $bootstrap->json('data.permissions') ?? [];
        $this->assertTrue((bool) ($permissions['menu.manage'] ?? false));
        $this->assertTrue((bool) ($permissions['orders.manage'] ?? false));
        $this->assertArrayHasKey('pos.manage', $permissions);

        $category = $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/cashier/v1/catalog/categories', [
                'name' => 'مشروبات '.uniqid(),
                'is_active' => true,
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotEmpty($category['id']);

        $this->withToken($token)
            ->withHeaders($headers)
            ->putJson('/api/cashier/v1/catalog/categories/'.$category['id'], [
                'name' => $category['name'].' محدث',
                'is_active' => true,
                'sort_order' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.sort_order', 2);

        $item = $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/cashier/v1/catalog/items', [
                'name' => 'عصير برتقال',
                'sku' => 'SKU-OJ-'.uniqid(),
                'barcode' => 'BC-OJ-'.uniqid(),
                'item_type' => 'مشروب',
                'pos_item_category_id' => $category['id'],
                'size_label' => 'وسط',
                'description' => 'طازج',
                'price' => 9.5,
                'currency' => 'SAR',
                'is_active' => true,
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->json('data.item');
        $this->assertEquals(9.5, (float) $item['price']);

        $updated = $this->withToken($token)
            ->withHeaders($headers)
            ->putJson('/api/cashier/v1/catalog/items/'.$item['id'], [
                'name' => 'عصير برتقال كبير',
                'sku' => $item['sku'],
                'barcode' => $item['barcode'],
                'item_type' => 'مشروب',
                'pos_item_category_id' => $category['id'],
                'size_label' => 'كبير',
                'description' => 'طازج',
                'price' => 12,
                'currency' => 'SAR',
                'is_active' => true,
                'sort_order' => 1,
            ])
            ->assertOk()
            ->json('data.item');
        $this->assertEquals(12.0, (float) $updated['price']);

        $this->withToken($token)
            ->withHeaders($headers)
            ->deleteJson('/api/cashier/v1/catalog/categories/'.$category['id'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن حذف تصنيف مرتبط بأصناف. انقل الأصناف أولاً.');

        $this->withToken($token)
            ->withHeaders($headers)
            ->deleteJson('/api/cashier/v1/catalog/items/'.$item['id'])
            ->assertOk();

        $this->withToken($token)
            ->withHeaders($headers)
            ->deleteJson('/api/cashier/v1/catalog/categories/'.$category['id'])
            ->assertOk();

        $settings = $this->withToken($token)
            ->withHeaders($headers)
            ->patchJson('/api/cashier/v1/settings/pos', [
                'tax_rate' => 15,
                'new_order_sound' => true,
                'enable_delivery' => true,
                'currency' => 'SAR',
            ])
            ->assertOk()
            ->json('data');

        $this->assertEquals(15.0, (float) $settings['tax_rate']);
        $this->assertTrue((bool) $settings['enable_delivery']);
        $this->assertTrue((bool) $settings['sound_enabled']);
        $this->assertSame('SAR', $settings['currency']);

        $plain = User::factory()->create(['password' => bcrypt('password')]);
        $workspace->users()->attach($plain->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($plain);
        $this->withHeaders($headers)
            ->patchJson('/api/cashier/v1/settings/pos', [
                'tax_rate' => 5,
            ])
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->postJson('/api/cashier/v1/catalog/categories', ['name' => 'ممنوع'])
            ->assertStatus(403);

        Sanctum::actingAs($owner);
        $activeItem = $this->makeItem($workspace, 20);
        $table = \App\Models\DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة جلسات '.uniqid(),
            'status' => 'available',
            'qr_token' => \Illuminate\Support\Str::random(48),
        ]);

        $create = $this->withToken($token)
            ->withHeaders($headers + ['Idempotency-Key' => 'sess-hist-'.uniqid()])
            ->postJson('/api/cashier/v1/orders', [
                'order_type' => 'table',
                'dining_table_id' => $table->id,
                'client_reference' => 'sess-order-'.uniqid(),
                'items' => [['pos_menu_item_id' => $activeItem->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $sessionId = (int) $create->json('data.table_session_id');
        $orderId = (int) $create->json('data.id');

        $this->withToken($token)
            ->withHeaders($headers)
            ->postJson("/api/cashier/v1/tables/{$table->id}/sessions/{$sessionId}/close", [
                'payment_method' => 'cash',
            ])
            ->assertOk();

        $show = $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/tables/'.$table->id)
            ->assertOk();

        $this->assertIsArray($show->json('data.sessions'));
        $this->assertGreaterThanOrEqual(1, count($show->json('data.sessions')));

        $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/tables/'.$table->id.'/sessions')
            ->assertOk()
            ->assertJsonPath('data.table_id', $table->id)
            ->assertJsonStructure(['data' => ['sessions' => [['id', 'status', 'opened_at', 'total']]]]);

        $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/orders/'.$orderId)
            ->assertOk()
            ->assertJsonStructure(['data' => ['placed_at']]);

        $report = $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/reports/daily')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'summary',
                'channel_stats',
                'quantity_by_type',
                'top_items',
                'sales_by_hour',
                'customer_summary',
                'recent_operations',
                'closed_orders',
                'all_orders',
                'invoices',
                'payment_methods',
            ]])
            ->json('data');

        $this->assertGreaterThanOrEqual(1, count($report['all_orders']));
        $this->assertGreaterThanOrEqual(1, count($report['closed_orders']));
        foreach ($report['sales_by_hour'] as $row) {
            $this->assertArrayHasKey('sales_total', $row);
            $this->assertArrayNotHasKey('total_sales', $row);
        }
    }

    private function makeItem(Workspace $workspace, float $price = 10): PosMenuItem
    {
        return PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'منتج كاشير',
            'price' => $price,
            'currency' => 'SAR',
            'is_active' => true,
            'sku' => 'SKU-'.uniqid(),
            'barcode' => 'BC-'.uniqid(),
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['pos', 'qr_menu', 'products', 'orders'] as $feature) {
            \App\Models\WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        $plan = \App\Models\Plan::query()
            ->where('workspace_type', $workspaceType)
            ->where('is_active', true)
            ->orderByDesc('price')
            ->first();

        if ($plan) {
            \App\Models\Subscription::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'workspace_id' => $workspace->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonth(),
                ]
            );
        }

        return [$user, $workspace];
    }
}
