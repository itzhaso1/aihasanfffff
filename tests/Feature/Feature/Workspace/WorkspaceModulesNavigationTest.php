<?php

namespace Tests\Feature\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Ui\CommunicationNav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkspaceModulesNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_dashboard_shows_chat_modules_without_business_nav(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $response = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.dashboard'));

        $response->assertOk()
            ->assertDontSee('POS / الإدارة')
            ->assertSee('الشات')
            ->assertSee('البريد')
            ->assertSee('صندوق البريد')
            ->assertSee('قوالب البريد')
            ->assertSee('المنتجات والمخزون')
            ->assertDontSee('الفوترة والحسابات')
            ->assertSee('النظام')
            ->assertSee(route('workspace.conversations.index'), false)
            ->assertSee(route('workspace.customers.index'), false)
            ->assertSee(route('workspace.channels.index'), false)
            ->assertDontSee('/workspace/products', false)
            ->assertDontSee('/workspace/inventory', false)
            ->assertDontSee('/workspace/finance', false)
            ->assertSee(route('workspace.ai-settings.edit'), false)
            ->assertSee(route('workspace.payments.index'), false)
            ->assertSee(route('workspace.subscriptions.index'), false)
            ->assertSee(route('workspace.employees.index'), false);

        $this->assertFalse(Route::has('workspace.finance.dashboard'));
        $this->assertFalse(Route::has('workspace.pos.tables.index'));
        $this->assertFalse(Route::has('workspace.products.index'));
    }

    public function test_communication_sidebar_excludes_chat_catalog_and_finance_catalog(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $response = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index'));

        $response->assertOk()
            ->assertSee('data-communication-nav="sidebar"', false)
            ->assertSee(route('workspace.conversations.index'), false)
            ->assertSee(route('workspace.customers.index'), false)
            ->assertSee(route('workspace.channels.index'), false)
            ->assertSee(route('workspace.emails.index'), false)
            ->assertSee(route('workspace.whatsapp-accounts.index'), false)
            ->assertSee(route('workspace.chat-catalog.index'), false)
            ->assertSee(route('workspace.ai-settings.edit'), false)
            ->assertSee('data-communication-nav="settings"', false)
            ->assertSee('data-communication-nav="archive"', false)
            ->assertSee(route('workspace.conversations.index', ['status' => 'archived']), false)
            ->assertSee('المنتجات والمخزون')
            ->assertDontSee(url('/workspace/chat-catalog/settings'), false);

        $labels = CommunicationNav::data()['secondaryLinks']->pluck('label')->all();
        $routes = CommunicationNav::data()['secondaryLinks']->pluck('route')->all();

        $this->assertSame([
            'المحادثات',
            'مؤرشفة',
            'العملاء',
            'القنوات',
            'واتساب',
            'المنتجات والمخزون',
            'البريد',
        ], $labels);
        $this->assertContains('workspace.chat-catalog.index', $routes);
        $this->assertNotContains('workspace.products.index', $routes);
        $this->assertNotContains('workspace.inventory.index', $routes);
        $this->assertTrue(Route::has('workspace.chat-catalog.index'));
        $this->assertFalse(Route::has('workspace.chat-catalog.settings.edit'));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
