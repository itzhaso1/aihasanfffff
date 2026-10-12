<?php

namespace Tests\Feature\Feature\Chat;

use App\Models\ChatCatalogItem;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Commerce\RetiredCustomerCheckout;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RetiredDigitalProductCommerceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create([
            'owner_user_id' => $this->user->id,
            'type' => 'company',
        ]);
        $this->workspace->users()->attach($this->user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()
            ->where('workspace_type', 'company')
            ->where('is_active', true)
            ->first();
        if ($plan) {
            Subscription::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $this->workspace->id],
                [
                    'workspace_id' => $this->workspace->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonth(),
                ]
            );
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->workspace->id);
        $this->user->givePermissionTo(['chat.catalog.view', 'chat.catalog.manage']);
        $registrar->setPermissionsTeamId(null);
    }

    public function test_web_catalog_index_hides_digital_inventory_and_keeps_product_management(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('workspace.chat-catalog.index'));

        $response->assertOk()
            ->assertDontSee('المخزون الرقمي', false)
            ->assertSee('مرجع للذكاء الاصطناعي', false)
            ->assertSee('+ إضافة منتج جديد', false);
    }

    public function test_web_rejects_creating_a_digital_product(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->from(route('workspace.chat-catalog.create'))
            ->post(route('workspace.chat-catalog.store'), [
                'name' => 'كود شحن',
                'price' => 20,
                'classification' => 'digital',
                'digital_subtype' => 'code',
                'inventory_policy' => 'digital_pool',
            ]);

        $response->assertRedirect(route('workspace.chat-catalog.create'));
        $response->assertSessionHasErrors(['classification']);
        $this->assertDatabaseMissing('chat_catalog_items', [
            'workspace_id' => $this->workspace->id,
            'name' => 'كود شحن',
        ]);
    }

    public function test_digital_inventory_http_surface_is_gone(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('workspace.chat-catalog.digital-inventory.index'))
            ->assertStatus(410);
    }

    public function test_mobile_digital_inventory_endpoints_are_gone(): void
    {
        $this->enableWorkspaceFeature($this->workspace, 'products');

        $this->actingAsMobile()
            ->getJson('/api/mobile/v1/catalog/digital-pools')
            ->assertStatus(410)
            ->assertSee(RetiredCustomerCheckout::DIGITAL_PRODUCTS_MESSAGE, false);

        $this->actingAsMobile()
            ->getJson('/api/mobile/v1/catalog/settings')
            ->assertOk()
            ->assertJsonPath('data.access.digital_inventory_view', false)
            ->assertJsonPath('data.access.digital_inventory_manage', false);
    }

    public function test_regular_products_can_still_be_created_as_ai_knowledge(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('workspace.chat-catalog.store'), [
                'name' => 'فستان حرير',
                'description' => 'فستان سهرة أحمر',
                'price' => 250,
                'currency' => 'SAR',
                'classification' => 'physical',
                'inventory_policy' => 'not_tracked',
                'is_active' => '1',
                'is_visible' => '1',
                'is_searchable' => '1',
            ]);

        $response->assertRedirect(route('workspace.chat-catalog.index'));
        $this->assertDatabaseHas('chat_catalog_items', [
            'workspace_id' => $this->workspace->id,
            'name' => 'فستان حرير',
            'classification' => 'physical',
        ]);
    }

    public function test_updating_a_legacy_digital_item_coerces_it_to_a_knowledge_product(): void
    {
        $item = ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'بطاقة قديمة',
            'slug' => 'legacy-card',
            'classification' => 'digital',
            'digital_subtype' => 'code',
            'inventory_policy' => 'digital_pool',
            'price' => 50,
            'currency' => 'SAR',
        ]);

        $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->put(route('workspace.chat-catalog.update', $item), [
                'name' => 'بطاقة معرفة',
                'description' => 'بطاقة شحن سابقة تُستخدم كمرجع فقط',
                'price' => 50,
                'currency' => 'SAR',
            ])
            ->assertRedirect();

        $item->refresh();
        $this->assertSame('بطاقة معرفة', $item->name);
        $this->assertSame('physical', $item->classification?->value);
        $this->assertNull($item->digital_subtype);
        $this->assertSame('not_tracked', $item->inventory_policy?->value);
    }

    private function actingAsMobile(): static
    {
        $this->flushHeaders();
        $this->enableWorkspaceFeature($this->workspace, 'products');

        $token = $this->user->createToken('retired-digital');
        $token->accessToken->forceFill(['workspace_id' => $this->workspace->id])->save();

        return $this->actingAs($this->user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $this->workspace->id)
            ->withHeader('Accept', 'application/json');
    }
}
