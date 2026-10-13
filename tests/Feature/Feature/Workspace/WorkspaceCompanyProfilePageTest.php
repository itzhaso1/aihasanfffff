<?php

namespace Tests\Feature\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCompanyProfile;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class WorkspaceCompanyProfilePageTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    public function test_owner_can_view_and_save_company_profile(): void
    {
        [$user, $workspace] = $this->workspaceOwner();
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->employee($workspace, ['name' => 'Nora']);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.ai-settings.edit', ['tab' => 'company']))
            ->assertOk()
            ->assertSee('نبذة عن الشركة')
            ->assertSee('ماذا نبيع؟')
            ->assertSee('حفظ')
            ->assertSee('إلغاء');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.ai-settings.company-profile.update'), [
                'company_name' => 'شركة النور',
                'about' => 'متجر ملابس',
                'offerings' => 'فساتين وأحذية',
                'services' => 'توصيل واستبدال',
                'audience' => 'عائلات',
                'reply_guidelines' => 'رد مختصر بالعربية',
            ])
            ->assertRedirect(route('workspace.ai-settings.edit', ['tab' => 'company']));

        $profile = WorkspaceCompanyProfile::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();

        $this->assertSame('شركة النور', $profile?->company_name);
        $this->assertSame('متجر ملابس', $profile?->about);
        $this->assertSame('فساتين وأحذية', $profile?->offerings);
        $this->assertSame('توصيل واستبدال', $profile?->services);
        $this->assertSame('عائلات', $profile?->audience);
        $this->assertSame('رد مختصر بالعربية', $profile?->reply_guidelines);
    }

    public function test_company_profile_rejects_overlong_fields(): void
    {
        [$user, $workspace] = $this->workspaceOwner();
        $this->enableWorkspaceFeature($workspace, 'ai');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.ai-settings.edit', ['tab' => 'company']))
            ->put(route('workspace.ai-settings.company-profile.update'), [
                'company_name' => str_repeat('ا', 121),
            ])
            ->assertRedirect(route('workspace.ai-settings.edit', ['tab' => 'company']))
            ->assertSessionHasErrors('company_name');

        $this->assertNull(
            WorkspaceCompanyProfile::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->first()
        );
    }

    public function test_member_cannot_update_company_profile(): void
    {
        [$owner, $workspace] = $this->workspaceOwner();
        $this->enableWorkspaceFeature($workspace, 'ai');
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.ai-settings.company-profile.update'), [
                'company_name' => 'يجب ألا يُحفظ',
            ])
            ->assertForbidden();
    }

    public function test_company_profile_is_isolated_per_workspace(): void
    {
        [$userA, $workspaceA] = $this->workspaceOwner('A Co');
        [$userB, $workspaceB] = $this->workspaceOwner('B Co');
        $this->enableWorkspaceFeature($workspaceA, 'ai');
        $this->enableWorkspaceFeature($workspaceB, 'ai');

        app(WorkspaceContext::class)->runFor($workspaceB, function () use ($workspaceB): void {
            WorkspaceCompanyProfile::factory()->create([
                'workspace_id' => $workspaceB->id,
                'company_name' => 'شركة ب فقط',
            ]);
        });

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.ai-settings.edit', ['tab' => 'company']))
            ->assertOk()
            ->assertDontSee('شركة ب فقط');

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->put(route('workspace.ai-settings.company-profile.update'), [
                'company_name' => 'شركة أ',
            ])
            ->assertRedirect();

        $this->assertSame(
            'شركة ب فقط',
            WorkspaceCompanyProfile::withoutGlobalScopes()
                ->where('workspace_id', $workspaceB->id)
                ->value('company_name')
        );
        $this->assertSame(
            'شركة أ',
            WorkspaceCompanyProfile::withoutGlobalScopes()
                ->where('workspace_id', $workspaceA->id)
                ->value('company_name')
        );
        unset($userB);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function workspaceOwner(string $name = 'Company Profile WS'): array
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

        return [$user, $workspace];
    }
}
