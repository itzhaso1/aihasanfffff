<?php

namespace Tests\Feature\Feature\Security;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\Workspace;
use App\Services\AI\Providers\GoogleAiStudioProvider;
use App\Services\AI\Providers\OpenAiProvider;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class LegacyApiFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fakeAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        $this->fakeAi = new FakeAiProvider;
        $this->app->instance(GoogleAiStudioProvider::class, $this->fakeAi);
        $this->app->instance(OpenAiProvider::class, $this->fakeAi);
        config(['services.google_ai_studio.key' => 'test-not-a-real-key']);
    }

    #[DataProvider('gatedLegacyEndpointsProvider')]
    public function test_feature_enabled_allows_authorized_legacy_access(
        string $feature,
        string $method,
        string $uri,
        array $payload,
        string $permission,
    ): void {
        [$owner, $workspace, $context] = $this->seedLegacyWorkspace($feature, enabled: true);
        $actor = $permission === 'owner'
            ? $owner
            : $this->memberWithPermissions($workspace, [$permission]);

        $this->actingAsApi($actor, $workspace)
            ->json($method, $uri, $this->replacePayload($payload, $context))
            ->assertSuccessful();
    }

    #[DataProvider('gatedLegacyEndpointsProvider')]
    public function test_feature_disabled_blocks_legacy_access_without_mutation(
        string $feature,
        string $method,
        string $uri,
        array $payload,
        string $permission,
    ): void {
        [$owner, $workspace, $context] = $this->seedLegacyWorkspace($feature, enabled: false);
        $actor = $permission === 'owner'
            ? $owner
            : $this->memberWithPermissions($workspace, [$permission]);

        $before = $this->resourceCount($feature, $workspace);

        $this->actingAsApi($actor, $workspace)
            ->json($method, $uri, $this->replacePayload($payload, $context))
            ->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available')
            ->assertJsonPath('feature', $feature);

        $this->assertSame($before, $this->resourceCount($feature, $workspace));
    }

    #[DataProvider('gatedLegacyMutationFeaturesProvider')]
    public function test_feature_disabled_blocks_legacy_mutation_even_for_owner(string $feature): void
    {
        [$owner, $workspace, $context] = $this->seedLegacyWorkspace($feature, enabled: false);
        [$method, $uri, $payload] = $this->mutationRequest($feature, $context);
        $before = $this->resourceCount($feature, $workspace);

        $this->actingAsApi($owner, $workspace)
            ->json($method, $uri, $payload)
            ->assertStatus(402)
            ->assertJsonPath('feature', $feature);

        $this->assertSame($before, $this->resourceCount($feature, $workspace));
    }

    #[DataProvider('gatedLegacyMutationFeaturesProvider')]
    public function test_non_member_is_rejected_before_feature_gate(string $feature): void
    {
        [, $workspace, $context] = $this->seedLegacyWorkspace($feature, enabled: true);
        [$method, $uri, $payload] = $this->mutationRequest($feature, $context);
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->json($method, $uri, $payload)
            ->assertForbidden();
    }

    public function test_orders_feature_disabled_returns_feature_gate_before_permission_denial(): void
    {
        [, $workspace] = $this->seedLegacyWorkspace('orders', enabled: false);
        $member = $this->memberWithPermissions($workspace, ['orders.manage']);

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/orders', [
                'currency' => 'SAR',
                'items' => [[
                    'product_id' => Product::withoutGlobalScopes()->where('workspace_id', $workspace->id)->value('id'),
                    'quantity' => 1,
                ]],
            ])
            ->assertStatus(402)
            ->assertJsonPath('feature', 'orders');
    }

    public function test_products_feature_is_isolated_per_workspace(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('Legacy Gate A');
        [$ownerB, $workspaceB] = $this->makeWorkspace('Legacy Gate B');
        $this->enableWorkspaceFeatures($workspaceA, ['products']);
        $this->enableWorkspaceFeature($workspaceB, 'products', false);

        $this->actingAsApi($ownerA, $workspaceA)
            ->postJson('/api/products', [
                'name' => 'Allowed Product',
                'sku' => 'GATE-A-1',
                'price' => 10,
                'currency' => 'SAR',
                'stock' => 1,
                'status' => 'active',
            ])
            ->assertCreated();

        $this->actingAsApi($ownerB, $workspaceB)
            ->postJson('/api/products', [
                'name' => 'Blocked Product',
                'sku' => 'GATE-B-1',
                'price' => 10,
                'currency' => 'SAR',
                'stock' => 1,
                'status' => 'active',
            ])
            ->assertStatus(402);

        $this->assertSame(1, Product::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(0, Product::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
    }

    public function test_customers_route_remains_available_without_products_feature(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Legacy Customers Open');
        $this->enableWorkspaceFeature($workspace, 'products', false);

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/customers', [
                'name' => 'Open Customer',
                'phone' => '0501234567',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Open Customer');
    }

    public function test_inventory_adjust_remains_available_without_pos_feature(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Legacy Inventory Open');
        $this->enableWorkspaceFeature($workspace, 'pos', false);
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Tracked Product',
            'slug' => 'tracked-product',
            'sku' => 'TRK-001',
            'price' => 20,
            'currency' => 'SAR',
            'stock' => 4,
            'inventory_tracking' => true,
            'status' => 'active',
        ]);

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.after_quantity', 6);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: string}>
     */
    public static function gatedLegacyEndpointsProvider(): array
    {
        return [
            'products index' => ['products', 'GET', '/api/products', [], 'owner'],
            'products store' => ['products', 'POST', '/api/products', [
                'name' => 'Legacy Gated Product',
                'sku' => 'LEG-GATE-PROD',
                'price' => 15,
                'currency' => 'SAR',
                'stock' => 2,
                'status' => 'active',
            ], 'products.manage'],
            'orders index' => ['orders', 'GET', '/api/orders', [], 'owner'],
            'orders store' => ['orders', 'POST', '/api/orders', [
                'currency' => 'SAR',
                'items' => [['product_id' => '{product_id}', 'quantity' => 1]],
            ], 'orders.manage'],
            'whatsapp index' => ['whatsapp', 'GET', '/api/whatsapp/accounts', [], 'owner'],
            'whatsapp store account' => ['whatsapp', 'POST', '/api/whatsapp/accounts', [
                'business_account_id' => 'biz-gate-1',
                'display_name' => 'Gate Account',
            ], 'owner'],
            'ai settings' => ['ai', 'GET', '/api/ai/settings', [], 'owner'],
            'ai reply' => ['ai', 'POST', '/api/ai/reply', [
                'conversation_id' => '{conversation_id}',
                'message_id' => '{message_id}',
            ], 'owner'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gatedLegacyMutationFeaturesProvider(): array
    {
        return [
            'products' => ['products'],
            'orders' => ['orders'],
            'whatsapp' => ['whatsapp'],
            'ai' => ['ai'],
        ];
    }

    /**
     * @return array{0: User, 1: Workspace, 2: array<string, mixed>}
     */
    private function seedLegacyWorkspace(string $feature, bool $enabled): array
    {
        [$owner, $workspace] = $this->makeWorkspace('Legacy Gate '.$feature);
        $this->enableWorkspaceFeatures($workspace, ['products', 'orders', 'whatsapp', 'ai']);
        $this->enableWorkspaceFeature($workspace, $feature, $enabled);

        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Gate Product',
            'slug' => 'gate-product-'.$feature,
            'sku' => 'GATE-'.$feature,
            'price' => 30,
            'currency' => 'SAR',
            'stock' => 5,
            'inventory_tracking' => true,
            'status' => 'active',
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Gate Customer',
            'phone' => '0507777777',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'web',
            'external_id' => 'gate-conv-'.$feature,
            'status' => 'open',
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'Need help',
        ]);

        return [$owner, $workspace, [
            'product_id' => $product->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function mutationRequest(string $feature, array $context): array
    {
        return match ($feature) {
            'products' => ['POST', '/api/products', [
                'name' => 'Blocked Product',
                'sku' => 'BLOCK-PROD',
                'price' => 10,
                'currency' => 'SAR',
                'stock' => 1,
                'status' => 'active',
            ]],
            'orders' => ['POST', '/api/orders', [
                'currency' => 'SAR',
                'items' => [[
                    'product_id' => $context['product_id'],
                    'quantity' => 1,
                ]],
            ]],
            'whatsapp' => ['POST', '/api/whatsapp/accounts', [
                'business_account_id' => 'blocked-biz',
                'display_name' => 'Blocked Account',
            ]],
            'ai' => ['POST', '/api/ai/reply', [
                'conversation_id' => $context['conversation_id'],
                'message_id' => $context['message_id'],
            ]],
            default => throw new \InvalidArgumentException('Unknown feature: '.$feature),
        };
    }

    private function resourceCount(string $feature, Workspace $workspace): int
    {
        return match ($feature) {
            'products' => Product::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(),
            'orders' => Order::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(),
            'whatsapp' => WhatsAppAccount::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(),
            'ai' => Message::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('ai_generated', true)
                ->count(),
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function replacePayload(array $payload, array $context): array
    {
        if ($payload === []) {
            return [];
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ($context as $key => $value) {
            $json = str_replace('{'.$key.'}', (string) $value, $json);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspace(string $name): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->ensureSubscription($workspace);

        return [$user, $workspace];
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

    private function ensureSubscription(Workspace $workspace): void
    {
        $plan = Plan::query()
            ->where('workspace_type', 'company')
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            return;
        }

        Subscription::withoutGlobalScopes()->updateOrCreate(
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

    private function actingAsApi(User $user, Workspace $workspace)
    {
        $this->flushHeaders();

        $token = $user->createToken('api');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
