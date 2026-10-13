<?php

namespace Tests\Feature\Feature\Chat;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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
    }

    public function test_web_catalog_and_digital_inventory_routes_are_gone(): void
    {
        $this->assertFalse(Route::has('workspace.chat-catalog.index'));
        $this->assertFalse(Route::has('workspace.chat-catalog.create'));
        $this->assertFalse(Route::has('workspace.chat-catalog.digital-inventory.index'));

        $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get('/workspace/chat-catalog')
            ->assertNotFound();

        $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get('/workspace/chat-catalog/digital-inventory')
            ->assertNotFound();
    }

    public function test_mobile_catalog_settings_and_digital_inventory_endpoints_are_gone(): void
    {
        $this->actingAsMobile()
            ->getJson('/api/mobile/v1/catalog/digital-pools')
            ->assertNotFound();

        $this->actingAsMobile()
            ->getJson('/api/mobile/v1/catalog/settings')
            ->assertNotFound();
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
