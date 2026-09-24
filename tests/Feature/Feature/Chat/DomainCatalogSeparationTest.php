<?php

namespace Tests\Feature\Feature\Chat;

use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\PosMenuItem;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\AI\AIService;
use App\Services\AI\Providers\GoogleAiStudioProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Support\Authorization\FoundationRolePermissions;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class DomainCatalogSeparationTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        $this->fake = new FakeAiProvider;
        $this->app->instance(GoogleAiStudioProvider::class, $this->fake);
        $this->app->instance(OpenAiProvider::class, $this->fake);
    }

    public function test_finance_product_does_not_appear_in_cashier_or_chat_ai(): void
    {
        [$owner, $workspace] = $this->workspaceOwner();
        $this->inWorkspace($workspace, function () use ($workspace): void {
            Product::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'FinanceCatalogWidget-A',
                'slug' => 'finance-widget-a',
                'sku' => 'FIN-A-1',
                'price' => 20,
                'currency' => 'SAR',
                'stock' => 4,
                'status' => 'active',
            ]);
            ChatCatalogItem::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'ChatDressBlack-A',
                'slug' => 'chat-dress-a',
                'sku' => 'CHAT-DRESS-A',
                'price' => 90,
                'currency' => 'SAR',
                'is_active' => true,
                'item_kind' => 'product',
                'tangibility' => 'tangible',
            ]);
        });

        Sanctum::actingAs($owner);
        $cashier = $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/cashier/v1/catalog/items')
            ->assertOk()
            ->json('data.items');
        $this->assertFalse(collect($cashier)->contains(fn ($row) => ($row['name'] ?? '') === 'FinanceCatalogWidget-A'));

        $this->generateAi($workspace, 'هل عندكم FinanceCatalogWidget-A؟');
        $this->assertStringNotContainsString('FinanceCatalogWidget-A', $this->aiSystemPrompt());
        $this->assertStringContainsString('لا تملك معلومة مؤكدة', $this->aiSystemPrompt());
    }

    public function test_cashier_item_does_not_appear_in_finance_or_chat_ai(): void
    {
        [$owner, $workspace] = $this->workspaceOwner();
        $this->inWorkspace($workspace, function () use ($workspace): void {
            PosMenuItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'CashierMenuLatte-A',
                'is_active' => true,
            ]);
            Product::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'FinanceOnlyBook-A',
                'slug' => 'finance-book-a',
                'sku' => 'FIN-BOOK-A',
                'price' => 15,
                'currency' => 'SAR',
                'stock' => 2,
                'status' => 'active',
            ]);
        });

        Sanctum::actingAs($owner);
        $finance = $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/finance/v1/products')
            ->assertOk()
            ->json('data');
        $this->assertFalse(collect($finance)->contains(fn ($row) => ($row['name'] ?? '') === 'CashierMenuLatte-A'));
        $this->assertTrue(collect($finance)->contains(fn ($row) => ($row['name'] ?? '') === 'FinanceOnlyBook-A'));

        $this->generateAi($workspace, 'هل عندكم CashierMenuLatte-A؟');
        $this->assertStringNotContainsString('CashierMenuLatte-A', $this->aiSystemPrompt());
    }

    public function test_electronic_menu_uses_active_cashier_items_only(): void
    {
        [, $workspace] = $this->workspaceOwner('store');
        $this->inWorkspace($workspace, function () use ($workspace): void {
            PosMenuItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'CashierActiveSoup-A',
                'is_active' => true,
            ]);
            PosMenuItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'CashierHiddenDraft-A',
                'is_active' => false,
            ]);
            Product::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'FinanceHiddenFromMenu',
                'slug' => 'finance-hidden-menu',
                'sku' => 'FIN-MENU-1',
                'price' => 10,
                'currency' => 'SAR',
                'stock' => 1,
                'status' => 'active',
            ]);
            ChatCatalogItem::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'ChatHiddenFromMenu',
                'slug' => 'chat-hidden-menu',
                'price' => 12,
                'currency' => 'SAR',
                'is_active' => true,
            ]);
        });

        $response = $this->get(route('menu.general', ['workspace' => $workspace->slug]));
        $response->assertOk();
        $response->assertSee('CashierActiveSoup-A', false);
        $response->assertDontSee('CashierHiddenDraft-A', false);
        $response->assertDontSee('FinanceHiddenFromMenu', false);
        $response->assertDontSee('ChatHiddenFromMenu', false);
    }

    public function test_chat_catalog_is_available_to_chat_ai_and_hidden_from_other_domains(): void
    {
        [$owner, $workspace] = $this->workspaceOwner();
        $this->inWorkspace($workspace, function () use ($workspace): void {
            ChatCatalogItem::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'فستان أسود',
                'slug' => 'black-dress-chat',
                'sku' => 'DRESS-BLK',
                'price' => 180,
                'currency' => 'SAR',
                'is_active' => true,
                'item_kind' => 'product',
                'attributes' => ['color' => 'أسود'],
            ]);
        });

        Sanctum::actingAs($owner);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/catalog?q=فستان')
            ->assertOk()
            ->assertJsonFragment(['name' => 'فستان أسود']);

        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/finance/v1/products')
            ->assertOk()
            ->assertJsonMissing(['name' => 'فستان أسود']);

        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/cashier/v1/catalog/items')
            ->assertOk();
        $cashier = $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/cashier/v1/catalog/items')
            ->json('data.items');
        $this->assertFalse(collect($cashier)->contains(fn ($row) => ($row['name'] ?? '') === 'فستان أسود'));

        $this->generateAi($workspace, 'هل عندكم فستان أسود مقاس M؟');
        $this->assertStringContainsString('فستان أسود', $this->aiSystemPrompt());
        $this->assertStringContainsString('كتالوج الشات', $this->aiSystemPrompt());
    }

    public function test_cross_workspace_catalog_isolation(): void
    {
        [$ownerA, $workspaceA] = $this->workspaceOwner();
        [$ownerB, $workspaceB] = $this->workspaceOwner();

        $this->inWorkspace($workspaceA, function () use ($workspaceA): void {
            ChatCatalogItem::query()->create([
                'workspace_id' => $workspaceA->id,
                'name' => 'ChatSecretWorkspaceA',
                'slug' => 'chat-secret-a',
                'price' => 10,
                'currency' => 'SAR',
                'is_active' => true,
            ]);
            Product::query()->create([
                'workspace_id' => $workspaceA->id,
                'name' => 'FinanceSecretWorkspaceA',
                'slug' => 'finance-secret-a',
                'sku' => 'FIN-SEC-A',
                'price' => 10,
                'currency' => 'SAR',
                'stock' => 1,
                'status' => 'active',
            ]);
            PosMenuItem::factory()->create([
                'workspace_id' => $workspaceA->id,
                'name' => 'CashierSecretWorkspaceA',
                'is_active' => true,
            ]);
        });
        $this->inWorkspace($workspaceB, function () use ($workspaceB): void {
            ChatCatalogItem::query()->create([
                'workspace_id' => $workspaceB->id,
                'name' => 'ChatSecretWorkspaceB',
                'slug' => 'chat-secret-b',
                'price' => 10,
                'currency' => 'SAR',
                'is_active' => true,
            ]);
        });

        $itemA = ChatCatalogItem::withoutGlobalScopes()->where('name', 'ChatSecretWorkspaceA')->firstOrFail();

        Sanctum::actingAs($ownerB);
        $this->withHeader('X-Workspace-Id', (string) $workspaceB->id)
            ->getJson('/api/mobile/v1/catalog/'.$itemA->id)
            ->assertNotFound();
        $listB = $this->withHeader('X-Workspace-Id', (string) $workspaceB->id)
            ->getJson('/api/mobile/v1/catalog')
            ->assertOk()
            ->json('data');
        $this->assertFalse(collect($listB)->contains(fn ($row) => ($row['name'] ?? '') === 'ChatSecretWorkspaceA'));

        $this->withHeader('X-Workspace-Id', (string) $workspaceB->id)
            ->getJson('/api/finance/v1/products')
            ->assertOk()
            ->assertJsonMissing(['name' => 'FinanceSecretWorkspaceA']);

        Sanctum::actingAs($ownerA);
        $this->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->getJson('/api/cashier/v1/catalog/items')
            ->assertOk()
            ->assertJsonFragment(['name' => 'CashierSecretWorkspaceA']);

        $this->generateAi($workspaceA, 'هل عندكم ChatSecretWorkspaceB؟');
        $this->assertStringNotContainsString('ChatSecretWorkspaceB', $this->aiSystemPrompt());
    }

    public function test_domain_permission_sets_are_isolated_and_can_combine(): void
    {
        [, $workspace] = $this->workspaceOwner();
        $this->inWorkspace($workspace, function () use ($workspace): void {
            ChatCatalogItem::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'ChatOnlyVisibleItem',
                'slug' => 'chat-only-visible',
                'price' => 11,
                'currency' => 'SAR',
                'is_active' => true,
            ]);
            Product::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'FinanceOnlyVisibleItem',
                'slug' => 'finance-only-visible',
                'sku' => 'FIN-ONLY-1',
                'price' => 11,
                'currency' => 'SAR',
                'stock' => 1,
                'status' => 'active',
            ]);
            PosMenuItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'CashierOnlyVisibleItem',
                'is_active' => true,
            ]);
        });

        $financeOnly = $this->memberWithRole($workspace, 'accountant', 'member');
        $cashierOnly = $this->memberWithPermissions($workspace, [
            'workspace.view',
            'pos.manage',
            'tables.manage',
            'menu.manage',
            'orders.manage',
            'customers.manage',
            'payments.manage',
        ]);
        $chatOnly = $this->memberWithPermissions($workspace, ['workspace.view', 'conversations.manage', 'chat.catalog.view', 'chat.catalog.manage']);
        $combined = $this->memberWithRole($workspace, 'manager', 'manager');

        Sanctum::actingAs($financeOnly);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/finance/v1/products')->assertOk()->assertJsonFragment(['name' => 'FinanceOnlyVisibleItem']);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/catalog')->assertForbidden();
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/cashier/v1/catalog/items')->assertForbidden();

        Sanctum::actingAs($cashierOnly);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/cashier/v1/catalog/items')->assertOk()->assertJsonFragment(['name' => 'CashierOnlyVisibleItem']);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/finance/v1/products')->assertForbidden();
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/catalog')->assertForbidden();

        Sanctum::actingAs($chatOnly);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/catalog')->assertOk()->assertJsonFragment(['name' => 'ChatOnlyVisibleItem']);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/finance/v1/products')->assertForbidden();
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/cashier/v1/catalog/items')->assertForbidden();

        Sanctum::actingAs($combined);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)->getJson('/api/finance/v1/products')->assertOk();
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)->getJson('/api/cashier/v1/catalog/items')->assertOk();
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)->getJson('/api/mobile/v1/catalog')->assertOk();

        $this->assertContains('chat.catalog.manage', FoundationRolePermissions::manager());
        $agent = Role::findByName('agent', 'web');
        $this->assertTrue($agent->hasPermissionTo('chat.catalog.manage'));
        $this->assertFalse($agent->hasPermissionTo('finance.view'));
        $this->assertFalse($agent->hasPermissionTo('invoices.view'));
        $receptionist = Role::findByName('receptionist', 'web');
        $this->assertFalse($receptionist->hasPermissionTo('invoices.view'));
        $this->assertFalse($receptionist->hasPermissionTo('receipts.view'));
        $this->assertFalse($receptionist->hasPermissionTo('chat.catalog.view'));
    }

    public function test_product_create_is_fail_closed_for_non_catalog_roles(): void
    {
        [, $workspace] = $this->workspaceOwner();
        $receptionist = $this->memberWithRole($workspace, 'receptionist', 'member');

        Sanctum::actingAs($receptionist);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/products', [
                'name' => 'ShouldNotCreate',
                'sku' => 'NOPE-1',
                'price' => 10,
                'currency' => 'SAR',
                'stock' => 1,
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_ai_does_not_use_another_workspace_chat_catalog_or_invent_unmatched_items(): void
    {
        [, $workspaceA] = $this->workspaceOwner();
        [, $workspaceB] = $this->workspaceOwner();
        $this->inWorkspace($workspaceB, function () use ($workspaceB): void {
            ChatCatalogItem::query()->create([
                'workspace_id' => $workspaceB->id,
                'name' => 'يخت ذهبي سري',
                'slug' => 'secret-yacht-b',
                'price' => 9999,
                'currency' => 'SAR',
                'is_active' => true,
            ]);
        });

        $this->generateAi($workspaceA, 'هل عندكم يخت ذهبي سري؟');
        $prompt = $this->aiSystemPrompt();
        $this->assertStringNotContainsString('يخت ذهبي سري', $prompt);
        $this->assertStringContainsString('لا تملك معلومة مؤكدة', $prompt);
        $this->assertStringContainsString('لا تختلق', $prompt);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function workspaceOwner(string $type = 'company'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $type,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['pos', 'qr_menu', 'products', 'orders', 'finance', 'ai', 'conversations'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['enabled' => true, 'source' => 'manual']
            );
        }

        $plan = Plan::query()->create([
            'code' => 'domain_sep_'.$workspace->id,
            'name' => 'Domain Sep',
            'workspace_type' => $type === 'store' ? 'store' : 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => ['ai', 'conversations', 'smart_replies', 'pos', 'products', 'finance'],
            'limits' => ['ai_usage' => 10000, 'ai_tokens' => 1000000],
        ]);
        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return [$user, $workspace];
    }

    private function memberWithRole(Workspace $workspace, string $role, string $membershipRole = 'member'): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => $membershipRole,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($workspace->id);
        $user->assignRole($role);
        $registrar->setPermissionsTeamId(null);

        return $user;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function memberWithPermissions(Workspace $workspace, array $permissions): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($workspace->id);
        $user->givePermissionTo($permissions);
        $registrar->setPermissionsTeamId(null);

        return $user;
    }

    private function generateAi(Workspace $workspace, string $content): void
    {
        [$conversation, $message] = $this->inWorkspace($workspace, function () use ($workspace, $content): array {
            $customer = Customer::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'عميل كتالوج',
                'phone' => '+9665'.str_pad((string) $workspace->id, 8, '0', STR_PAD_LEFT),
                'orders_count' => 0,
                'total_purchases' => 0,
                'metadata' => [],
            ]);
            $conversation = Conversation::query()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => 'whatsapp',
                'external_id' => 'conv-cat-'.$workspace->id.'-'.uniqid(),
                'status' => 'open',
                'ai_enabled' => true,
                'metadata' => [],
            ]);
            $message = Message::query()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => $content,
                'ai_generated' => false,
                'metadata' => [],
            ]);

            return [$conversation, $message];
        });

        $this->inWorkspace($workspace, function () use ($conversation, $message): void {
            app(AIService::class)->generateReply($conversation, $message);
        });
    }

    private function aiSystemPrompt(): string
    {
        return (string) ($this->fake->lastMessages[0]['content'] ?? '');
    }
}
