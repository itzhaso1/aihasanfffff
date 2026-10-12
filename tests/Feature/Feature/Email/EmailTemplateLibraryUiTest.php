<?php

namespace Tests\Feature\Feature\Email;

use App\Models\EmailBranding;
use App\Models\EmailTemplatePreference;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailTemplateLibraryUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_can_browse_library_categories_from_registry(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.emails.templates.index'))
            ->assertOk()
            ->assertSee('قوالب البريد')
            ->assertSee('المقاولات')
            ->assertSee('التجارة الإلكترونية')
            ->assertSee('قالب عام')
            ->assertDontSee('password', false);
    }

    public function test_category_cards_allow_selecting_any_registered_template(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.emails.templates.category', 'construction'))
            ->assertOk()
            ->assertSee('ترحيب المقاولات')
            ->assertSee('Construction Welcome')
            ->assertSee('استخدام القالب')
            ->assertSee('مسودة')
            ->assertDontSee('غير متاح للإرسال');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.templates.use', 'construction_notification'))
            ->assertRedirect(route('workspace.emails.templates.edit'));

        $this->assertDatabaseHas('email_template_preferences', [
            'workspace_id' => $workspace->id,
            'template_key' => 'construction_notification',
        ]);
    }

    public function test_using_an_active_template_stores_only_the_key_then_customizes_settings(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'company_name' => 'أفق المقاولات',
            'primary_color' => '#112233',
            'secondary_color' => '#445566',
            'background_color' => '#EFEFEF',
            'text_color' => '#101010',
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.templates.use', 'construction_welcome'))
            ->assertRedirect(route('workspace.emails.templates.edit'));

        $this->assertDatabaseHas('email_template_preferences', [
            'workspace_id' => $workspace->id,
            'template_key' => 'construction_welcome',
        ]);

        $preference = EmailTemplatePreference::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();
        $this->assertNotNull($preference);
        $this->assertSame([], $preference->settings ?? []);
        $this->assertStringNotContainsString('<html', json_encode($preference->settings));

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.emails.templates.edit'))
            ->assertOk()
            ->assertSee('تخصيص')
            ->assertSee('اللون الرئيسي')
            ->assertSee('اللون الثانوي')
            ->assertSee('لون الخلفية')
            ->assertSee('لون النص')
            ->assertSee('أفق المقاولات');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.emails.templates.update'), [
                'companyName' => 'أفق المحدّثة',
                'subject' => 'مرحباً بالمقاول',
                'primaryColor' => '#abcdef',
                'html' => '<script>stolen</script>',
            ])
            ->assertRedirect(route('workspace.emails.templates.edit'));

        $preference->refresh();
        $this->assertSame('construction_welcome', $preference->template_key);
        $this->assertSame('أفق المحدّثة', $preference->settings['companyName']);
        $this->assertSame('#ABCDEF', $preference->settings['primaryColor']);
        $this->assertArrayNotHasKey('html', $preference->settings);
    }

    public function test_preview_renders_the_library_blade_with_workspace_branding_and_rtl(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'company_name' => 'أفق المقاولات',
            'primary_color' => '#112233',
            'secondary_color' => '#445566',
            'background_color' => '#EFEFEF',
            'text_color' => '#101010',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.emails.templates.preview', ['construction', 'construction_welcome']))
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertNotFalse(stripos($html, '#0f766e'));
        $this->assertStringNotContainsString('#112233', $html);
        $this->assertStringContainsString('أفق المقاولات', $html);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.templates.use', 'construction_welcome'));

        $preview = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.templates.live-preview'), [
                'primaryColor' => '#998877',
                'companyName' => 'معاينة مباشرة',
            ]);

        $preview->assertOk();
        $html = $preview->getContent();
        $this->assertStringContainsString('#998877', $html);
        $this->assertStringContainsString('معاينة مباشرة', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
    }

    public function test_workspace_b_cannot_read_or_change_workspace_a_selection(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$userB, $workspaceB] = $this->createWorkspaceOwner('store');

        EmailTemplatePreference::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'template_key' => 'construction_welcome',
            'settings' => ['companyName' => 'شركة ألف السرية'],
        ]);

        $this->actingAs($userB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->get(route('workspace.emails.templates.edit'))
            ->assertNotFound();

        $this->actingAs($userB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->put(route('workspace.emails.templates.update'), [
                'companyName' => 'اختراق',
            ])
            ->assertNotFound();

        $this->assertSame(
            'شركة ألف السرية',
            EmailTemplatePreference::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->value('settings')['companyName'] ?? null
        );
        $this->assertNull(
            EmailTemplatePreference::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->first()
        );
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
            'name' => $workspaceType === 'company' ? 'شركة محلية' : 'متجر محلي',
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'code' => $workspaceType.'_templates_'.uniqid(),
            'name' => 'Email Templates Plan',
            'tier' => 'pro',
            'workspace_type' => $workspaceType,
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => ['email', 'dashboard', 'subscription'],
            'limits' => ['email_sends' => 1000],
        ]);

        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return [$user, $workspace];
    }
}
