<?php

namespace Tests\Feature\Feature\Security;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LegacyApiStoreAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_owner_can_use_legacy_store_resource_endpoints(): void
    {
        [$owner, $workspace] = $this->makeWorkspace();
        $product = $this->makeProduct($workspace);

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/categories', ['name' => 'Legacy Category'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Legacy Category');

        $customerResponse = $this->actingAsApi($owner, $workspace)
            ->postJson('/api/customers', [
                'name' => 'Legacy Customer',
                'phone' => '0501111111',
            ])
            ->assertCreated();
        $customerId = (int) $customerResponse->json('data.id');

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/conversations', [
                'customer_id' => $customerId,
                'channel' => 'web',
                'external_id' => 'legacy-conv-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'web');

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/orders', [
                'currency' => 'SAR',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated();

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/products', [
                'name' => 'Legacy Product',
                'sku' => 'LEG-001',
                'price' => 25,
                'currency' => 'SAR',
                'stock' => 5,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Legacy Product');

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 2,
            ])
            ->assertCreated();
    }

    public function test_member_without_elevated_roles_cannot_mutate_protected_resources(): void
    {
        [$owner, $workspace] = $this->makeWorkspace();
        $member = $this->attachMember($workspace, 'member');
        $product = $this->makeProduct($workspace);
        $category = Category::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Protected Category',
            'slug' => 'protected-category',
            'is_active' => true,
        ]);
        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'ORD-LEG-001',
            'currency' => 'SAR',
            'subtotal_amount' => 100,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 100,
            'status' => 'confirmed',
            'payment_status' => 'pending',
        ]);

        $this->actingAsApi($member, $workspace)
            ->putJson('/api/categories/'.$category->id, ['name' => 'Hacked Category'])
            ->assertForbidden();

        $this->actingAsApi($member, $workspace)
            ->putJson('/api/orders/'.$order->id, ['status' => 'cancelled'])
            ->assertForbidden();

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/products', [
                'name' => 'Member Product',
                'sku' => 'MEM-001',
                'price' => 10,
                'currency' => 'SAR',
                'stock' => 1,
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->assertSame('Protected Category', $category->fresh()->name);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_member_with_products_manage_permission_can_create_products(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $member = $this->memberWithPermissions($workspace, ['products.manage']);

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/products', [
                'name' => 'Permitted Product',
                'sku' => 'PERM-001',
                'price' => 15,
                'currency' => 'SAR',
                'stock' => 3,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Permitted Product');
    }

    public function test_non_member_and_unauthenticated_requests_are_rejected(): void
    {
        [$owner, $workspace] = $this->makeWorkspace();
        $outsider = User::factory()->create();

        $this->actingAsApi($outsider, $workspace)
            ->getJson('/api/orders')
            ->assertForbidden();

        $this->actingAsApi($outsider, $workspace)
            ->postJson('/api/customers', [
                'name' => 'Outsider Customer',
                'phone' => '0502222222',
            ])
            ->assertForbidden();

        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/categories', ['name' => 'Guest Category'])
            ->assertForbidden();

        $this->assertDatabaseMissing('customers', [
            'workspace_id' => $workspace->id,
            'name' => 'Outsider Customer',
        ]);
        $this->assertDatabaseMissing('categories', [
            'workspace_id' => $workspace->id,
            'name' => 'Guest Category',
        ]);

        $this->assertSame(0, Customer::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, Category::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_cross_workspace_resource_access_is_rejected(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('Workspace A');
        [$ownerB, $workspaceB] = $this->makeWorkspace('Workspace B');

        $orderB = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'order_number' => 'ORD-B-001',
            'currency' => 'SAR',
            'subtotal_amount' => 50,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 50,
            'status' => 'confirmed',
            'payment_status' => 'pending',
        ]);
        $customerB = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Customer B',
            'phone' => '0503333333',
        ]);
        $conversationB = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'customer_id' => $customerB->id,
            'channel' => 'web',
            'external_id' => 'conv-b-1',
            'status' => 'open',
        ]);
        $messageB = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'conversation_id' => $conversationB->id,
            'customer_id' => $customerB->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'Secret B',
        ]);

        $this->actingAsApi($ownerA, $workspaceA)
            ->getJson('/api/orders/'.$orderB->id)
            ->assertNotFound();

        $this->actingAsApi($ownerA, $workspaceA)
            ->putJson('/api/orders/'.$orderB->id, ['status' => 'cancelled'])
            ->assertNotFound();

        $this->actingAsApi($ownerA, $workspaceA)
            ->getJson('/api/customers/'.$customerB->id)
            ->assertNotFound();

        $this->actingAsApi($ownerA, $workspaceA)
            ->getJson('/api/conversations/'.$conversationB->id)
            ->assertNotFound();

        $this->actingAsApi($ownerA, $workspaceA)
            ->getJson('/api/messages/'.$messageB->id)
            ->assertNotFound();

        $this->actingAsApi($ownerA, $workspaceA)
            ->getJson('/api/messages?conversation_id='.$conversationB->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conversation_id']);

        $this->assertSame('confirmed', $orderB->fresh()->status);
        $this->assertSame('Customer B', $customerB->fresh()->name);
    }

    public function test_cross_workspace_payment_creation_is_rejected(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('Pay A');
        [, $workspaceB] = $this->makeWorkspace('Pay B');

        $orderB = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'order_number' => 'ORD-PAY-B',
            'currency' => 'SAR',
            'subtotal_amount' => 75,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 75,
            'status' => 'confirmed',
            'payment_status' => 'pending',
        ]);

        $beforeCount = Payment::withoutGlobalScopes()->count();

        $this->actingAsApi($ownerA, $workspaceA)
            ->postJson('/api/payments', ['order_id' => $orderB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_id']);

        $this->assertSame($beforeCount, Payment::withoutGlobalScopes()->count());
        $this->assertSame('pending', $orderB->fresh()->payment_status);
    }

    public function test_authorized_user_can_list_resources_in_own_workspace_only(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->attachOwnedWorkspace($user, 'List A');
        $workspaceB = $this->attachOwnedWorkspace($user, 'List B');

        Category::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'name' => 'Cat A',
            'slug' => 'cat-a',
            'is_active' => true,
        ]);
        Category::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Cat B',
            'slug' => 'cat-b',
            'is_active' => true,
        ]);

        $this->actingAsApi($user, $workspaceA)
            ->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cat A');

        $this->actingAsApi($user, $workspaceB)
            ->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cat B');
    }

    public function test_authorized_user_can_read_messages_for_own_conversation(): void
    {
        [$owner, $workspace] = $this->makeWorkspace();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Msg Customer',
            'phone' => '0504444444',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'web',
            'external_id' => 'conv-msg-1',
            'status' => 'open',
        ]);
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'Hello owner',
        ]);

        $this->actingAsApi($owner, $workspace)
            ->getJson('/api/messages?conversation_id='.$conversation->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id);

        $this->actingAsApi($owner, $workspace)
            ->getJson('/api/messages/'.$message->id)
            ->assertOk()
            ->assertJsonPath('data.content', 'Hello owner');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspace(string $name = 'Legacy Auth Workspace'): array
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

    private function attachOwnedWorkspace(User $user, string $name): Workspace
    {
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

        return $workspace;
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
            'name' => 'Legacy Product Base',
            'slug' => 'legacy-product-base',
            'sku' => 'LEG-BASE',
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
