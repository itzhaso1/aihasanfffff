<?php

namespace Tests\Feature\Feature\Chat;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Enums\WorkerEngagementStatus;
use App\Models\ChatCatalogItem;
use App\Models\Commerce\Seller;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Commerce\CommerceCartService;
use App\Services\Commerce\CommerceOrderService;
use App\Support\Feature\ChatBusinessFeatureKeys;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatBusinessFeatureFlagIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_catalog_and_commerce_work_when_business_features_are_disabled(): void
    {
        [$owner, $workspace, $item] = $this->chatWorkspace();
        $this->disableBusinessFeatures($workspace);

        $this->assertSame('Published item', $item->fresh()->name);
        $this->assertSame('25.00', $item->fresh()->price);

        $order = $this->inWorkspace($workspace, function () use ($workspace, $item) {
            $carts = app(CommerceCartService::class);
            $cart = $carts->addItem($workspace, $carts->create($workspace, currency: 'SAR'), $item->id, null, 2);

            return app(CommerceOrderService::class)->createDraftFromCart($workspace, $cart, 'flag-isolation-0001');
        });

        $this->assertSame('commerce', $order->source);
        $this->assertSame('50.00', $order->total_amount);
        $this->assertNull($order->finance_invoice_id);
        $this->assertFalse(Route::has('workspace.chat-catalog.index'));
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get('/workspace/chat-catalog')
            ->assertNotFound();
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get('/workspace/chat-catalog/settings')
            ->assertNotFound();
    }

    public function test_inbox_and_worker_contract_pricing_work_without_business_features(): void
    {
        [$owner, $workspace] = $this->companyWorkspace();
        $this->enableWorkspaceFeatures($workspace, ['conversations', 'orders']);
        $this->disableBusinessFeatures($workspace);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbox Isolation Customer',
            'phone' => '0501111222',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'external_id' => 'flag-isolation:inbox',
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.dashboard'))
            ->assertOk();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('Inbox Isolation Customer');

        $worker = User::factory()->create();
        $profile = WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => 'Flag Isolation Worker',
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Independent worker.',
            'sales_experience_level' => 'senior',
        ]);
        $engagement = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active,
            'job_title' => 'Isolation role',
            'created_by' => $owner->id,
            'active_at' => now(),
            'offered_contract_amount_minor' => 75000,
            'offered_contract_currency' => 'SAR',
            'agreed_contract_amount_minor' => 75000,
            'agreed_contract_currency' => 'SAR',
        ]);

        $this->assertSame(75000, $engagement->fresh()->agreed_contract_amount_minor);
        $this->assertSame('SAR', $engagement->fresh()->agreed_contract_currency);
    }

    public function test_chat_plan_payload_hides_business_keys_and_business_routes_are_gone(): void
    {
        [$owner, $workspace] = $this->companyWorkspace();
        $this->enableWorkspaceFeatures($workspace, ['products', 'orders', 'ai']);
        $this->attachOfficialPlan($workspace, 'pro');
        $this->disableBusinessFeatures($workspace);

        Sanctum::actingAs($owner);
        $token = $owner->createToken('flag-isolation');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        $plan = $this->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/plan')
            ->assertOk()
            ->json('data');

        $this->assertIsArray($plan['features'] ?? null);
        $this->assertSame([], array_values(array_intersect(ChatBusinessFeatureKeys::KEYS, $plan['features'])));
        $this->assertSame(
            [],
            array_values(array_intersect(
                ChatBusinessFeatureKeys::KEYS,
                array_column($plan['comparison'] ?? [], 'key'),
            )),
        );

        $catalog = $this->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/plans')
            ->assertOk()
            ->json('data');

        foreach ($catalog['plans'] ?? [] as $row) {
            $this->assertSame([], array_values(array_intersect(ChatBusinessFeatureKeys::KEYS, $row['features'] ?? [])));
        }
        $this->assertSame(
            [],
            array_values(array_intersect(
                ChatBusinessFeatureKeys::KEYS,
                array_column($catalog['comparison'] ?? [], 'key'),
            )),
        );

        $legacyPlans = $this->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/subscriptions/plans')
            ->assertOk()
            ->json('data');

        foreach ($legacyPlans ?? [] as $row) {
            $this->assertSame([], array_values(array_intersect(ChatBusinessFeatureKeys::KEYS, $row['features'] ?? [])));
        }

        $legacyCurrent = $this->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/subscriptions/current')
            ->assertOk()
            ->json('data.entitlements.features');

        $this->assertSame([], array_values(array_intersect(ChatBusinessFeatureKeys::KEYS, $legacyCurrent ?? [])));

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get('/workspace/finance')
            ->assertNotFound();
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get('/workspace/pos')
            ->assertNotFound();
    }

    public function test_chat_runtime_sources_do_not_check_business_feature_gates(): void
    {
        $paths = [
            app_path('Services/Chat'),
            app_path('Services/Commerce'),
            app_path('Services/Inbox'),
            app_path('Services/Worker'),
            app_path('Services/AI'),
            app_path('Http/Controllers/Workspace/Commerce'),
            app_path('Http/Controllers/Api/Mobile/V1/CommerceCartController.php'),
            app_path('Http/Controllers/Api/Mobile/V1/CommerceOrderController.php'),
            app_path('Http/Controllers/Api/Mobile/V1/ChatCatalogController.php'),
        ];

        foreach ($paths as $path) {
            $files = is_dir($path)
                ? File::allFiles($path)
                : (is_file($path) ? [new \SplFileInfo($path)] : []);
            foreach ($files as $file) {
                $source = file_get_contents($file->getPathname());
                $this->assertIsString($source);
                $this->assertStringNotContainsString("workspaceHasFeature(\$workspace, 'finance')", $source);
                $this->assertStringNotContainsString("workspaceHasFeature(\$workspace, 'pos')", $source);
                $this->assertStringNotContainsString("workspaceHasFeature(\$workspace, 'inventory')", $source);
                $this->assertStringNotContainsString('workspace.feature:finance', $source);
                $this->assertStringNotContainsString('workspace.feature:pos', $source);
                $this->assertStringNotContainsString('workspace.feature:inventory', $source);
            }
        }
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function companyWorkspace(): array
    {
        $user = User::factory()->create();
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

    /**
     * @return array{0: User, 1: Workspace, 2: ChatCatalogItem}
     */
    private function chatWorkspace(): array
    {
        [$user, $workspace] = $this->companyWorkspace();
        $this->enableWorkspaceFeatures($workspace, ['products', 'orders']);
        $seller = Seller::factory()->create(['workspace_id' => $workspace->id]);

        $item = $this->inWorkspace($workspace, function () use ($workspace, $seller): ChatCatalogItem {
            $catalog = app(ChatCatalogRootService::class)->forWorkspace($workspace);
            $catalog->update([
                'commerce_seller_id' => $seller->id,
                'status' => ChatCatalogOperatingStatus::Published,
                'published_at' => now(),
                'default_currency' => 'SAR',
                'channel_visibility' => ['chat' => true],
            ]);

            return ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'commerce_seller_id' => $seller->id,
                'name' => 'Published item',
                'price' => '25.00',
                'currency' => 'SAR',
                'classification' => 'physical',
                'inventory_policy' => ChatCatalogInventoryPolicy::FiniteStock,
                'stock' => 10,
                'is_active' => true,
                'is_visible' => true,
                'publication_status' => 'published',
                'published_at' => now(),
                'tax_display_behavior' => 'tax_downstream',
            ]);
        });

        return [$user, $workspace, $item];
    }

    private function disableBusinessFeatures(Workspace $workspace): void
    {
        foreach (ChatBusinessFeatureKeys::KEYS as $feature) {
            $this->enableWorkspaceFeature($workspace, $feature, false);
        }
    }

    private function attachOfficialPlan(Workspace $workspace, string $code): void
    {
        $plan = Plan::query()->where('code', $code)->firstOrFail();
        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }
}
