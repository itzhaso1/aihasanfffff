<?php

namespace Tests\Feature\Feature\Performance;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BootstrapBoundedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_finance_bootstrap_catalog_is_capped_with_has_more_meta(): void
    {
        [$user, $workspace] = $this->makeFinanceWorkspace();

        Sanctum::actingAs($user);
        $response = $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/finance/v1/bootstrap')
            ->assertOk();

        $catalog = $response->json('data.catalog');
        $meta = $response->json('data.catalog_meta');

        $this->assertLessThanOrEqual(200, count($catalog['customers'] ?? []));
        $this->assertLessThanOrEqual(500, count($catalog['products'] ?? []));
        $this->assertSame(200, $meta['customers']['limit'] ?? null);
        $this->assertSame(500, $meta['products']['limit'] ?? null);
        $this->assertArrayHasKey('has_more', $meta['customers']);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeFinanceWorkspace(): array
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
        $this->enableWorkspaceFeature($workspace, 'finance');

        return [$user, $workspace->fresh()];
    }
}
