<?php

namespace Tests\Feature\Feature\Mobile;

use App\Models\ChatCatalogItem;
use App\Models\Commerce\Seller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileChatCatalogAdminParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        Storage::fake('public');
    }

    public function test_sellers_are_workspace_scoped_and_foreign_ids_are_rejected(): void
    {
        [, $workspaceA] = $this->seedWorkspace('Seller A');
        [, $workspaceB] = $this->seedWorkspace('Seller B');
        $this->enableWorkspaceFeature($workspaceA, 'products');
        $this->enableWorkspaceFeature($workspaceB, 'products');
        $managerA = $this->memberWithPermissions($workspaceA, ['chat.catalog.view', 'chat.catalog.manage']);
        $managerB = $this->memberWithPermissions($workspaceB, ['chat.catalog.view', 'chat.catalog.manage']);

        $sellerA = Seller::factory()->create([
            'workspace_id' => $workspaceA->id,
            'name' => 'Seller Workspace A',
        ]);
        $sellerB = Seller::factory()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Seller Workspace B',
        ]);

        $this->actingAsMobile($managerA, $workspaceA)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'فستان',
                'price' => 10,
                'currency' => 'SAR',
                'commerce_seller_id' => $sellerA->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.commerce_seller_id', $sellerA->id);

        $this->actingAsMobile($managerA, $workspaceA)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'فستان أجنبي',
                'price' => 10,
                'currency' => 'SAR',
                'commerce_seller_id' => $sellerB->id,
            ])
            ->assertStatus(422);

        $this->actingAsMobile($managerA, $workspaceA)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'فستان مفقود',
                'price' => 10,
                'currency' => 'SAR',
                'commerce_seller_id' => 999999,
            ])
            ->assertStatus(422);

        $this->actingAsMobile($managerB, $workspaceB)
            ->getJson('/api/mobile/v1/catalog/sellers')
            ->assertNotFound();
    }

    public function test_media_gallery_reorder_delete_and_primary_use_existing_sync(): void
    {
        [, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'products');
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $created = $this->actingAsMobile($manager, $workspace)
            ->post('/api/mobile/v1/catalog', [
                'name' => 'فستان سهرة',
                'price' => '40.00',
                'currency' => 'SAR',
                'gallery_files' => [
                    UploadedFile::fake()->image('one.jpg', 20, 20),
                    UploadedFile::fake()->image('two.jpg', 20, 20),
                ],
            ])
            ->assertCreated();

        $itemId = (int) $created->json('data.id');
        $shown = $this->actingAsMobile($manager, $workspace)
            ->getJson('/api/mobile/v1/catalog/'.$itemId)
            ->assertOk();

        $media = $shown->json('data.media');
        $this->assertIsArray($media);
        $this->assertCount(2, $media);
        $this->assertNotEmpty($media[0]['url']);

        $keepId = (int) $media[1]['id'];
        $this->actingAsMobile($manager, $workspace)
            ->patchJson('/api/mobile/v1/catalog/'.$itemId, [
                'media' => [
                    [
                        'id' => $keepId,
                        'kind' => 'primary',
                        'path' => $media[1]['path'],
                        'sort_order' => 0,
                    ],
                ],
            ])
            ->assertOk();

        $after = $this->actingAsMobile($manager, $workspace)
            ->getJson('/api/mobile/v1/catalog/'.$itemId)
            ->assertOk()
            ->json('data.media');

        $this->assertCount(1, $after);
        $this->assertSame($keepId, (int) $after[0]['id']);
        $this->assertSame('primary', $after[0]['kind']);
        $this->assertSame(0, (int) $after[0]['sort_order']);
    }

    public function test_structured_attributes_round_trip_accepts_key_aliases(): void
    {
        [, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'products');
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $created = $this->actingAsMobile($manager, $workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'فستان',
                'price' => 12,
                'currency' => 'SAR',
                'structured_attributes' => [
                    ['key' => 'color', 'value' => 'أسود', 'value_type' => 'string'],
                ],
                'variants' => [
                    [
                        'name' => 'S',
                        'price' => 12,
                        'sale_price' => 10,
                        'compare_at_price' => 15,
                        'status' => 'published',
                        'is_visible' => true,
                        'structured_attributes' => [
                            ['key' => 'size', 'value' => 'S', 'value_type' => 'string'],
                        ],
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.structured_attributes.0.attribute_key', 'color')
            ->assertJsonPath('data.structured_attributes.0.key', 'color')
            ->assertJsonPath('data.structured_attributes.0.value', 'أسود');

        $itemId = (int) $created->json('data.id');
        $this->actingAsMobile($manager, $workspace)
            ->getJson('/api/mobile/v1/catalog/'.$itemId)
            ->assertOk()
            ->assertJsonPath('data.variants.0.sale_price', null)
            ->assertJsonPath('data.variants.0.structured_attributes.0.attribute_key', 'size');
    }

    public function test_catalog_settings_endpoints_are_gone(): void
    {
        [, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'products');
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $this->actingAsMobile($manager, $workspace)
            ->getJson('/api/mobile/v1/catalog/settings')
            ->assertNotFound();

        $this->actingAsMobile($manager, $workspace)
            ->post('/api/mobile/v1/catalog/settings', [
                'name' => 'كتالوج',
                'logo_file' => UploadedFile::fake()->image('logo.png', 32, 32),
            ])
            ->assertNotFound();
    }

    public function test_access_snapshot_hides_manage_for_view_only_members(): void
    {
        [, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'products');
        $viewer = $this->memberWithPermissions($workspace, ['chat.catalog.view']);
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $this->actingAsMobile($manager, $workspace)
            ->getJson('/api/mobile/v1/catalog')
            ->assertOk();

        $this->actingAsMobile($viewer, $workspace)
            ->getJson('/api/mobile/v1/catalog')
            ->assertOk();

        $this->actingAsMobile($viewer, $workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'ممنوع',
                'price' => 1,
                'currency' => 'SAR',
            ])
            ->assertForbidden();
    }

    public function test_cross_workspace_item_media_is_not_found(): void
    {
        [, $workspaceA] = $this->seedWorkspace('Media A');
        [, $workspaceB] = $this->seedWorkspace('Media B');
        $this->enableWorkspaceFeature($workspaceA, 'products');
        $this->enableWorkspaceFeature($workspaceB, 'products');
        $managerA = $this->memberWithPermissions($workspaceA, ['chat.catalog.view', 'chat.catalog.manage']);
        $managerB = $this->memberWithPermissions($workspaceB, ['chat.catalog.view', 'chat.catalog.manage']);

        $itemId = (int) $this->actingAsMobile($managerA, $workspaceA)
            ->postJson('/api/mobile/v1/catalog', ['name' => 'خاص', 'price' => 5, 'currency' => 'SAR'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMobile($managerB, $workspaceB)
            ->patchJson('/api/mobile/v1/catalog/'.$itemId, [
                'media' => [],
            ])
            ->assertNotFound();

        $this->assertTrue(ChatCatalogItem::withoutGlobalScopes()->whereKey($itemId)->exists());
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function seedWorkspace(string $name = 'Catalog Admin'): array
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
        $token = $user->createToken('mobile-catalog-admin');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
