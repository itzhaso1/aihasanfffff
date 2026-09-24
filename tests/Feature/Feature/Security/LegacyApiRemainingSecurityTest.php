<?php

namespace Tests\Feature\Feature\Security;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LegacyApiRemainingSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    #[DataProvider('legacyStoreEndpointsProvider')]
    public function test_member_without_permission_cannot_create_legacy_resources(
        string $method,
        string $uri,
        array $payload,
        string $countModel,
    ): void {
        [, $workspace] = $this->makeWorkspace();
        $member = $this->attachMember($workspace, 'member');
        $product = $this->makeProduct($workspace);

        $payload = $this->replacePayload($payload, $workspace, $product);
        $before = $countModel::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count();

        $this->actingAsApi($member, $workspace)
            ->json($method, $uri, $payload)
            ->assertForbidden();

        $this->assertSame(
            $before,
            $countModel::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(),
        );
    }

    public function test_member_with_orders_manage_permission_can_create_orders(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $member = $this->memberWithPermissions($workspace, ['orders.manage']);
        $product = $this->makeProduct($workspace);

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/orders', [
                'currency' => 'SAR',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();
    }

    public function test_member_with_customers_manage_permission_can_create_customers(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $member = $this->memberWithPermissions($workspace, ['customers.manage']);

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/customers', [
                'name' => 'Permitted Customer',
                'phone' => '0507000001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Permitted Customer');
    }

    public function test_cross_workspace_customer_id_on_conversation_create_is_rejected(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('Conv A');
        [, $workspaceB] = $this->makeWorkspace('Conv B');
        $customerB = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Customer B',
            'phone' => '0509000001',
        ]);
        $before = Conversation::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count();

        $this->actingAsApi($ownerA, $workspaceA)
            ->postJson('/api/conversations', [
                'customer_id' => $customerB->id,
                'channel' => 'web',
                'external_id' => 'xws-conv-1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);

        $this->assertSame($before, Conversation::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
    }

    public function test_cross_workspace_order_id_on_payment_create_is_rejected(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('Pay A');
        [, $workspaceB] = $this->makeWorkspace('Pay B');
        $orderB = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'order_number' => 'ORD-B-API-001',
            'currency' => 'SAR',
            'subtotal_amount' => 50,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 50,
            'status' => 'confirmed',
            'payment_status' => 'pending',
        ]);
        $before = Payment::withoutGlobalScopes()->count();

        $this->actingAsApi($ownerA, $workspaceA)
            ->postJson('/api/payments', ['order_id' => $orderB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_id']);

        $this->assertSame($before, Payment::withoutGlobalScopes()->count());
    }

    public function test_cross_workspace_conversation_id_on_message_index_is_rejected(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('Msg A');
        [, $workspaceB] = $this->makeWorkspace('Msg B');
        $customerB = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Msg Customer B',
            'phone' => '0509000002',
        ]);
        $conversationB = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'customer_id' => $customerB->id,
            'channel' => 'web',
            'external_id' => 'msg-b-1',
            'status' => 'open',
        ]);

        $this->actingAsApi($ownerA, $workspaceA)
            ->getJson('/api/messages?conversation_id='.$conversationB->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conversation_id']);
    }

    /**
     * @return array<string, array{0:string,1:string,2:array<string,mixed>,3:class-string}>
     */
    public static function legacyStoreEndpointsProvider(): array
    {
        return [
            'categories' => ['POST', '/api/categories', ['name' => 'Blocked Category'], Category::class],
            'customers' => ['POST', '/api/customers', ['name' => 'Blocked Customer', 'phone' => '0508000001'], Customer::class],
            'conversations' => ['POST', '/api/conversations', ['channel' => 'web', 'external_id' => 'blocked-conv'], Conversation::class],
            'orders' => ['POST', '/api/orders', [
                'currency' => 'SAR',
                'items' => [['product_id' => '{product_id}', 'quantity' => 1]],
            ], Order::class],
            'payments' => ['POST', '/api/payments', ['order_id' => '{order_id}'], Payment::class],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function replacePayload(array $payload, Workspace $workspace, Product $product): array
    {
        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'ORD-MEMBER-BLOCK',
            'currency' => 'SAR',
            'subtotal_amount' => 25,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 25,
            'status' => 'confirmed',
            'payment_status' => 'pending',
        ]);

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $json = str_replace(
            ['{product_id}', '{order_id}'],
            [(string) $product->id, (string) $order->id],
            $json,
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspace(string $name = 'Legacy API Security'): array
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
        $this->enableWorkspaceFeatures($workspace, ['products', 'orders', 'whatsapp', 'ai']);

        return [$user, $workspace];
    }

    private function attachMember(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

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

    private function makeProduct(Workspace $workspace): Product
    {
        return Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Legacy API Product',
            'slug' => 'legacy-api-product',
            'sku' => 'LEG-API-001',
            'price' => 100,
            'currency' => 'SAR',
            'stock' => 10,
            'inventory_tracking' => true,
            'status' => 'active',
        ]);
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
