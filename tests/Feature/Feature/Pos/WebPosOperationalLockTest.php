<?php

namespace Tests\Feature\Feature\Pos;

use App\Models\DiningTable;
use App\Models\PosMenuItem;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPosOperationalLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_cashier_redirects_and_session_posts_are_forbidden(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة ويب',
            'status' => 'available',
            'qr_token' => 'qr-web-lock',
        ]);
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.cashier.index'))
            ->assertRedirect(route('workspace.pos.tables.index'));

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.pos.tables.sessions.open', $table))
            ->assertForbidden();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('workspace.pos.orders.store'), [
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.tables.index'))
            ->assertOk()
            ->assertSee('مشغولة', false)
            ->assertSee('تجديد QR');

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))
            ->assertOk();
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = \App\Models\User::factory()->create(['password' => bcrypt('password')]);
        $workspace = \App\Models\Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        foreach (['pos', 'qr_menu', 'products', 'orders'] as $feature) {
            \App\Models\WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        return [$user, $workspace];
    }
}
