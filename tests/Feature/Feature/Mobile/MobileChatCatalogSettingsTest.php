<?php

namespace Tests\Feature\Feature\Mobile;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileChatCatalogSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_authorized_member_can_get_and_update_advanced_catalog_settings(): void
    {
        [, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'products');
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $this->actingAsMobile($manager, $workspace)
            ->getJson('/api/mobile/v1/catalog/settings')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAsMobile($manager, $workspace)
            ->patchJson('/api/mobile/v1/catalog/settings', [
                'availability_mode' => 'scheduled',
                'seo' => ['title' => 'كتالوج تجريبي', 'robots' => 'noindex'],
                'search_weights' => ['sku' => 8, 'exact_name' => 10],
                'customer_service_policy' => [
                    'inquiry_mode' => 'inquiry_only',
                    'out_of_hours_message' => 'سنعود غداً',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.availability_mode', 'scheduled')
            ->assertJsonPath('data.seo.title', 'كتالوج تجريبي')
            ->assertJsonPath('data.search_weights.sku', 8)
            ->assertJsonPath('data.customer_service_policy.inquiry_mode', 'inquiry_only');

        $this->actingAsMobile($manager, $workspace)
            ->getJson('/api/mobile/v1/catalog/settings')
            ->assertJsonPath('data.seo.title', 'كتالوج تجريبي')
            ->assertJsonPath('data.customer_service_policy.out_of_hours_message', 'سنعود غداً');
    }

    public function test_catalog_settings_are_workspace_isolated(): void
    {
        [, $workspaceA] = $this->seedWorkspace('Catalog A');
        [, $workspaceB] = $this->seedWorkspace('Catalog B');
        $this->enableWorkspaceFeature($workspaceA, 'products');
        $this->enableWorkspaceFeature($workspaceB, 'products');
        $managerA = $this->memberWithPermissions($workspaceA, ['chat.catalog.view', 'chat.catalog.manage']);
        $managerB = $this->memberWithPermissions($workspaceB, ['chat.catalog.view', 'chat.catalog.manage']);

        $this->actingAsMobile($managerA, $workspaceA)
            ->patchJson('/api/mobile/v1/catalog/settings', [
                'seo' => ['title' => 'Workspace A SEO'],
            ])
            ->assertOk();

        $this->actingAsMobile($managerA, $workspaceB)
            ->getJson('/api/mobile/v1/catalog/settings')
            ->assertForbidden();

        $this->actingAsMobile($managerB, $workspaceB)
            ->getJson('/api/mobile/v1/catalog/settings')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Workspace A SEO']);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function seedWorkspace(string $name = 'Catalog Settings WS'): array
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

        $token = $user->createToken('mobile-catalog-settings');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
