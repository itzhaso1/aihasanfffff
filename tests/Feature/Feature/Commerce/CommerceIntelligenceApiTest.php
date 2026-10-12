<?php

namespace Tests\Feature\Feature\Commerce;

use App\Models\ChatCatalogItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Commerce\Intelligence\CommerceAttributeService;
use App\Services\Commerce\Intelligence\CommerceIntelligenceProfileService;
use App\Services\Commerce\Intelligence\CommerceRequirementService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceIntelligenceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_mobile_api_retrieves_intelligence_profile_attributes_and_evaluates(): void
    {
        [$user, $workspace, $item] = $this->setupIntelligenceWorkspace();

        // 1. Get Profile
        $this->actingAsMobile($user, $workspace)
            ->getJson('/api/mobile/v1/commerce/intelligence/profile')
            ->assertOk()
            ->assertJsonPath('data.primary_domain', 'fashion');

        // 2. Get Attributes
        $this->actingAsMobile($user, $workspace)
            ->getJson('/api/mobile/v1/commerce/intelligence/attributes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'bust_cm');

        // 3. Get Requirements
        $this->actingAsMobile($user, $workspace)
            ->getJson('/api/mobile/v1/commerce/intelligence/requirements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'bust_cm');

        // 4. Get Item Intelligence
        $this->actingAsMobile($user, $workspace)
            ->getJson('/api/mobile/v1/commerce/intelligence/items/'.$item->id)
            ->assertOk()
            ->assertJsonPath('data.item_id', $item->id)
            ->assertJsonPath('data.item_name', 'Abaya Elegance');

        // 5. Evaluate Recommendation (Missing Bust -> Insufficient Information)
        $this->actingAsMobile($user, $workspace)
            ->postJson('/api/mobile/v1/commerce/intelligence/evaluate', [
                'item_id' => $item->id,
                'customer_context' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'insufficient_information');

        // 6. Evaluate Recommendation (With Bust -> Compatible)
        $this->actingAsMobile($user, $workspace)
            ->postJson('/api/mobile/v1/commerce/intelligence/evaluate', [
                'item_id' => $item->id,
                'customer_context' => ['bust_cm' => 90],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'compatible');
    }

    public function test_api_rejects_cross_workspace_item_evaluation(): void
    {
        [$user, $workspace, $item] = $this->setupIntelligenceWorkspace();
        $otherWorkspace = Workspace::factory()->create();

        $this->actingAsMobile($user, $otherWorkspace)
            ->getJson('/api/mobile/v1/commerce/intelligence/items/'.$item->id)
            ->assertNotFound();
    }

    private function setupIntelligenceWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $workspace->users()->attach($user->id, ['membership_role' => 'owner', 'status' => 'active']);
        $this->enableWorkspaceFeature($workspace, 'products');
        $this->enableWorkspaceFeature($workspace, 'orders');

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $user, &$item): void {
            app(CommerceIntelligenceProfileService::class)->updateProfile(
                workspace: $workspace,
                primaryDomain: 'fashion',
                actor: $user,
            );

            app(CommerceAttributeService::class)->createDefinition($workspace, [
                'key' => 'bust_cm',
                'display_name' => 'محيط الصدر',
                'data_type' => 'measurement',
                'unit' => 'cm',
            ], $user);

            app(CommerceRequirementService::class)->createDefinition($workspace, [
                'key' => 'bust_cm',
                'label' => 'محيط الصدر',
                'data_type' => 'measurement',
                'unit' => 'cm',
                'is_required' => true,
            ], $user);

            $item = ChatCatalogItem::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Abaya Elegance',
                'slug' => 'abaya-elegance',
                'status' => 'active',
                'publication_status' => 'published',
            ]);
        });

        return [$user, $workspace, $item];
    }

    private function actingAsMobile(User $user, Workspace $workspace): self
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
