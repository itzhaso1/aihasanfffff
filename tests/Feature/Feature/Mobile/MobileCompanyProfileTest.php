<?php

namespace Tests\Feature\Feature\Mobile;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCompanyProfile;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileCompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_owner_can_read_and_update_company_profile_via_mobile_api(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');

        $this->actingAsMobile($owner, $workspace)
            ->getJson('/api/mobile/v1/ai/company-profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_name', null)
            ->assertJsonMissingPath('data.workspace_id');

        $this->actingAsMobile($owner, $workspace)
            ->putJson('/api/mobile/v1/ai/company-profile', [
                'company_name' => 'شركة النور',
                'about' => 'متجر تجزئة',
                'offerings' => 'ملابس',
                'services' => 'توصيل',
                'audience' => 'شباب',
                'reply_guidelines' => 'كن مختصراً',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'تم حفظ نبذة الشركة.')
            ->assertJsonPath('data.company_name', 'شركة النور')
            ->assertJsonPath('data.about', 'متجر تجزئة')
            ->assertJsonPath('data.offerings', 'ملابس')
            ->assertJsonPath('data.services', 'توصيل')
            ->assertJsonPath('data.audience', 'شباب')
            ->assertJsonPath('data.reply_guidelines', 'كن مختصراً');

        $this->actingAsMobile($owner, $workspace)
            ->getJson('/api/mobile/v1/ai/company-profile')
            ->assertOk()
            ->assertJsonPath('data.company_name', 'شركة النور');
    }

    public function test_company_profile_is_isolated_between_workspaces(): void
    {
        [$ownerA, $workspaceA] = $this->seedWorkspace('Mobile A');
        [$ownerB, $workspaceB] = $this->seedWorkspace('Mobile B');
        $this->enableWorkspaceFeature($workspaceA, 'ai');
        $this->enableWorkspaceFeature($workspaceB, 'ai');

        app(WorkspaceContext::class)->runFor($workspaceB, function () use ($workspaceB): void {
            WorkspaceCompanyProfile::factory()->create([
                'workspace_id' => $workspaceB->id,
                'company_name' => 'سر مساحة ب',
            ]);
        });

        $this->actingAsMobile($ownerA, $workspaceA)
            ->getJson('/api/mobile/v1/ai/company-profile')
            ->assertOk()
            ->assertJsonMissing(['سر مساحة ب'])
            ->assertJsonPath('data.company_name', null);

        $this->actingAsMobile($ownerA, $workspaceB)
            ->getJson('/api/mobile/v1/ai/company-profile')
            ->assertForbidden();

        $this->actingAsMobile($ownerA, $workspaceA)
            ->putJson('/api/mobile/v1/ai/company-profile', [
                'company_name' => 'شركة أ',
            ])
            ->assertOk();

        $this->assertSame(
            'سر مساحة ب',
            WorkspaceCompanyProfile::withoutGlobalScopes()
                ->where('workspace_id', $workspaceB->id)
                ->value('company_name')
        );
        unset($ownerB);
    }

    public function test_member_without_ai_manage_cannot_read_or_update_company_profile(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');
        $member = $this->memberWithPermissions($workspace, []);

        $this->actingAsMobile($member, $workspace)
            ->getJson('/api/mobile/v1/ai/company-profile')
            ->assertForbidden();

        $this->actingAsMobile($member, $workspace)
            ->putJson('/api/mobile/v1/ai/company-profile', [
                'company_name' => 'ممنوع',
            ])
            ->assertForbidden();
        unset($owner);
    }

    public function test_update_rejects_overlong_company_name(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');

        $this->actingAsMobile($owner, $workspace)
            ->putJson('/api/mobile/v1/ai/company-profile', [
                'company_name' => str_repeat('ب', 121),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_name');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function seedWorkspace(string $name = 'Mobile Company Profile WS'): array
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

        if ($permissions !== []) {
            $registrar = app(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId($workspace->id);
            $user->givePermissionTo($permissions);
            $registrar->setPermissionsTeamId(null);
        }

        return $user;
    }

    private function actingAsMobile(User $user, Workspace $workspace)
    {
        $this->flushHeaders();

        $token = $user->createToken('mobile-company-profile');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
