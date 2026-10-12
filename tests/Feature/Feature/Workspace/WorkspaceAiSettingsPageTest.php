<?php

namespace Tests\Feature\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class WorkspaceAiSettingsPageTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    public function test_workspace_ai_settings_do_not_show_the_platform_model(): void
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
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->employee($workspace, ['name' => 'Nora']);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.ai-settings.edit'))
            ->assertOk()
            ->assertSee('الذكاء الاصطناعي')
            ->assertSee('Nora')
            ->assertDontSee('نموذج المنصة')
            ->assertDontSee('يُحدد من منصة الإدارة فقط، ويعمل به الموقع بالكامل.');
    }
}
