<?php

namespace Tests\Feature\Feature\Security;

use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileChatCatalogFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_products_enabled_allows_authorized_mobile_catalog_crud(): void
    {
        [$owner, $workspace] = $this->seedWorkspace(productsEnabled: true);
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $this->actingAsMobile($manager, $workspace)
            ->postJson('/api/mobile/v1/catalog/categories', ['name' => 'Mobile Gate Category'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Mobile Gate Category');

        $categoryId = (int) ChatCatalogCategory::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'Mobile Gate Category')
            ->value('id');

        $this->actingAsMobile($manager, $workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'Mobile Gate Item',
                'price' => 25,
                'currency' => 'SAR',
                'chat_catalog_category_id' => $categoryId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Mobile Gate Item');

        $itemId = (int) ChatCatalogItem::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'Mobile Gate Item')
            ->value('id');

        $this->actingAsMobile($manager, $workspace)
            ->getJson('/api/mobile/v1/catalog')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Mobile Gate Item']);

        $this->actingAsMobile($manager, $workspace)
            ->patchJson('/api/mobile/v1/catalog/'.$itemId, ['name' => 'Mobile Gate Item Updated'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mobile Gate Item Updated');

        $this->actingAsMobile($owner, $workspace)
            ->deleteJson('/api/mobile/v1/catalog/'.$itemId)
            ->assertOk();
    }

    public function test_products_disabled_blocks_mobile_catalog_reads_and_mutations_with_feature_gate(): void
    {
        [$owner, $workspace] = $this->seedWorkspace(productsEnabled: false);

        $this->actingAsMobile($owner, $workspace)
            ->getJson('/api/mobile/v1/catalog')
            ->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available')
            ->assertJsonPath('feature', 'products');

        $this->actingAsMobile($owner, $workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'Blocked Item',
                'price' => 10,
                'currency' => 'SAR',
            ])
            ->assertStatus(402)
            ->assertJsonPath('feature', 'products');
    }

    public function test_products_disabled_does_not_mutate_chat_catalog(): void
    {
        [$owner, $workspace] = $this->seedWorkspace(productsEnabled: false);
        $beforeItems = ChatCatalogItem::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count();
        $beforeCategories = ChatCatalogCategory::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count();

        $this->actingAsMobile($owner, $workspace)
            ->postJson('/api/mobile/v1/catalog/categories', ['name' => 'Should Not Persist'])
            ->assertStatus(402);

        $this->actingAsMobile($owner, $workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'Should Not Persist Item',
                'price' => 12,
                'currency' => 'SAR',
            ])
            ->assertStatus(402);

        $this->assertSame($beforeItems, ChatCatalogItem::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame($beforeCategories, ChatCatalogCategory::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_unauthorized_member_cannot_mutate_catalog_even_when_products_enabled(): void
    {
        [, $workspace] = $this->seedWorkspace(productsEnabled: true);
        $viewer = $this->memberWithPermissions($workspace, ['chat.catalog.view']);
        $beforeItems = ChatCatalogItem::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count();

        $this->actingAsMobile($viewer, $workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'Permission Blocked Item',
                'price' => 15,
                'currency' => 'SAR',
            ])
            ->assertForbidden();

        $this->assertSame($beforeItems, ChatCatalogItem::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_catalog_settings_endpoints_are_unavailable(): void
    {
        [, $workspace] = $this->seedWorkspace(productsEnabled: true);
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $this->actingAsMobile($manager, $workspace)
            ->patchJson('/api/mobile/v1/catalog/settings', [
                'default_currency' => 'JOD',
            ])
            ->assertNotFound();

        $this->actingAsMobile($manager, $workspace)
            ->patchJson('/api/mobile/v1/catalog/settings/communication', [
                'primary_language' => 'ar',
            ])
            ->assertNotFound();
    }

    public function test_non_member_is_rejected_before_products_feature_gate(): void
    {
        [, $workspace] = $this->seedWorkspace(productsEnabled: true);
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'Outsider Item',
                'price' => 10,
                'currency' => 'SAR',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function seedWorkspace(bool $productsEnabled): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => 'Mobile Catalog Gate',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->enableWorkspaceFeature($workspace, 'products', $productsEnabled);

        $plan = Plan::query()
            ->where('workspace_type', 'company')
            ->where('is_active', true)
            ->first();

        if ($plan) {
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

    private function actingAsMobile(User $user, Workspace $workspace)
    {
        $this->flushHeaders();

        $token = $user->createToken('mobile-catalog-gate');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
