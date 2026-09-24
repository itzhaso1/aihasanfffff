<?php

namespace Tests\Feature\Feature\Email;

use App\Mail\WorkspaceBrandedEmail;
use App\Models\EmailAccount;
use App\Models\EmailBranding;
use App\Models\EmailMessage;
use App\Models\EmailTemplatePreference;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Email\EmailBrandingHtmlBuilder;
use App\Support\Email\EmailBrandPublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_can_save_and_reload_email_branding(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.emails.branding.update'), $this->payload([
                'company_name' => 'الأفق للتقنية',
                'company_description' => 'حلول تقنية متقدمة',
                'primary_color' => '#102A43',
                'secondary_color' => '#14B8A6',
                'background_color' => '#EEF2F7',
                'text_color' => '#1F2937',
                'footer_email' => 'info@horizon.test',
                'social_linkedin' => 'https://linkedin.com/company/horizon',
                'show_cta' => '1',
                'cta_label' => 'ابدأ الآن',
                'cta_url' => 'https://horizon.test/start',
                'current_tab' => 'colors',
            ]))
            ->assertRedirect(route('workspace.emails.branding.edit', ['tab' => 'colors']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('email_brandings', [
            'workspace_id' => $workspace->id,
            'company_name' => 'الأفق للتقنية',
            'footer_email' => 'info@horizon.test',
            'primary_color' => '#102A43',
            'secondary_color' => '#14B8A6',
            'background_color' => '#EEF2F7',
            'text_color' => '#1F2937',
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.emails.branding.edit', ['tab' => 'colors']))
            ->assertOk()
            ->assertSee('الأفق للتقنية')
            ->assertSee('حلول تقنية متقدمة')
            ->assertSee('هوية البريد الإلكتروني')
            ->assertSee('حفظ التغييرات')
            ->assertSee('اللون الرئيسي')
            ->assertSee('اللون الثانوي')
            ->assertSee('لون الخلفية')
            ->assertSee('لون النص')
            ->assertSee('#102a43')
            ->assertSee('#14b8a6')
            ->assertSee('#eef2f7')
            ->assertSee('#1f2937')
            ->assertDontSee('password', false);
    }

    public function test_preview_and_outbound_html_are_workspace_branded_and_gmail_safe(): void
    {
        Storage::fake('public');
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $account = $this->account($workspace, 'info@horizon.test');
        $logoPath = 'workspaces/'.$workspace->id.'/email-branding/logo.png';
        Storage::disk('public')->put($logoPath, 'png-bytes');

        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'company_name' => 'الأفق للتقنية',
            'logo_path' => $logoPath,
            'primary_color' => '#102A43',
            'secondary_color' => '#2563EB',
            'background_color' => '#F4F6FB',
            'text_color' => '#111827',
            'header_style' => 'solid',
            'show_header_logo' => true,
            'show_header_company_name' => true,
            'footer_email' => 'info@horizon.test',
            'show_footer_email' => true,
            'show_cta' => true,
            'cta_label' => 'ابدأ الآن',
            'cta_url' => 'https://horizon.test/start',
            'social_linkedin' => 'https://linkedin.com/company/horizon',
            'show_footer_social' => true,
        ]);

        $preview = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.branding.preview'), $this->payload([
                'company_name' => 'الأفق للتقنية',
                'preview_subject' => 'مرحباً من الأفق',
                'preview_body' => 'أهلاً بك في الأفق للتقنية',
            ]));

        $preview->assertOk();
        $html = $preview->getContent();
        $this->assertGmailSafeHtml($html);
        $this->assertStringContainsString('الأفق للتقنية', $html);
        $this->assertStringContainsString('مرحباً من الأفق', $html);
        $this->assertStringNotContainsStringIgnoringCase('hasem', $html);
        $this->assertStringNotContainsString('حاسم', $html);
        $this->assertDoesNotMatchRegularExpression('#src="(/storage/|file:|data:)#', $html);

        $logoUrl = EmailBrandPublicUrl::forWorkspacePath((int) $workspace->id, $logoPath);
        $this->assertNotNull($logoUrl);
        $this->assertTrue(EmailBrandPublicUrl::isSafeRemoteUrl($logoUrl));
        $this->assertStringContainsString($logoUrl, $html);

        $message = $this->outbound($account, 'customer@example.com');
        $mailableHtml = $this->inWorkspace($workspace, fn () => (new WorkspaceBrandedEmail($message, $account))->render());
        $this->assertGmailSafeHtml($mailableHtml);
        $this->assertStringContainsString('الأفق للتقنية', $mailableHtml);
        $this->assertStringContainsString('cid:workspace-logo', $mailableHtml);
        $this->assertStringContainsString('cid:social-linkedin', $mailableHtml);
        $this->assertStringNotContainsString($logoUrl, $mailableHtml);
        $this->assertStringNotContainsStringIgnoringCase('hasem', $mailableHtml);
    }

    public function test_branding_preview_renders_the_selected_library_template(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'company_name' => 'أفق المقاولات',
            'primary_color' => '#102A43',
            'secondary_color' => '#14B8A6',
            'background_color' => '#EEF2F7',
            'text_color' => '#1F2937',
        ]);
        EmailTemplatePreference::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'template_key' => 'construction_welcome',
            'settings' => [],
        ]);

        $pageHtml = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.emails.branding.edit', ['tab' => 'preview']))
            ->assertOk()
            ->assertSee('ترحيب المقاولات')
            ->assertSee('نبني بثقة، وننفذ بجودة')
            ->assertSee('أفق المقاولات')
            ->getContent();
        $this->assertNotFalse(stripos($pageHtml, '#0f766e'));
        $this->assertStringNotContainsString('#102A43', $pageHtml);

        $html = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.branding.preview'), $this->payload([
                'company_name' => 'شركة المعاينة',
                'primary_color' => '#998877',
                'preview_subject' => 'عنوان قالب المقاولات',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('نبني بثقة، وننفذ بجودة', $html);
        $this->assertStringContainsString('نخطط معك ... وننجز معك', $html);
        $this->assertStringContainsString('شركة المعاينة', $html);
        $this->assertStringContainsString('#998877', $html);
        $this->assertStringContainsString('عنوان قالب المقاولات', $html);
        $this->assertStringNotContainsString('أفق المقاولات', $html);
    }

    public function test_branding_preview_uses_saved_template_colors_instead_of_workspace_branding_colors(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'company_name' => 'أفق المقاولات',
            'primary_color' => '#102A43',
            'secondary_color' => '#2563EB',
            'background_color' => '#F4F6FB',
            'text_color' => '#111827',
        ]);
        EmailTemplatePreference::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'template_key' => 'construction_welcome',
            'settings' => [
                'primaryColor' => '#AABBCC',
                'secondaryColor' => '#DDEEFF',
            ],
        ]);

        $page = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.emails.branding.edit', ['tab' => 'preview']))
            ->assertOk();

        $page->assertSee('#aabbcc', false);
        $page->assertDontSee('#102A43', false);

        $html = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.branding.preview'), [
                'company_name' => 'أفق المقاولات',
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('#AABBCC', $html);
        $this->assertStringNotContainsString('#102A43', $html);
    }

    public function test_branding_preview_does_not_use_another_workspace_selected_template(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$userB, $workspaceB] = $this->createWorkspaceOwner('store');

        EmailTemplatePreference::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'template_key' => 'construction_welcome',
            'settings' => ['companyName' => 'شركة ألف السرية'],
        ]);

        $html = $this->actingAs($userB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->post(route('workspace.emails.branding.preview'), $this->payload([
                'company_name' => 'شركة باء',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('شركة باء', $html);
        $this->assertStringNotContainsString('نبني بثقة، وننفذ بجودة', $html);
        $this->assertStringNotContainsString('شركة ألف السرية', $html);
    }

    public function test_workspace_b_branding_and_logo_never_leak_into_workspace_a(): void
    {
        Storage::fake('public');
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$userB, $workspaceB] = $this->createWorkspaceOwner('store');

        $logoA = 'workspaces/'.$workspaceA->id.'/email-branding/a.png';
        $logoB = 'workspaces/'.$workspaceB->id.'/email-branding/b.png';
        Storage::disk('public')->put($logoA, 'a');
        Storage::disk('public')->put($logoB, 'b');

        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'company_name' => 'شركة ألف',
            'logo_path' => $logoA,
            'primary_color' => '#111111',
            'secondary_color' => '#222222',
            'background_color' => '#F4F6FB',
            'text_color' => '#111827',
        ]);
        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'company_name' => 'شركة باء السرية',
            'logo_path' => $logoB,
            'primary_color' => '#abcdef',
            'secondary_color' => '#123456',
            'background_color' => '#F4F6FB',
            'text_color' => '#111827',
        ]);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.emails.branding.edit'))
            ->assertOk()
            ->assertSee('شركة ألف')
            ->assertDontSee('شركة باء السرية')
            ->assertDontSee($logoB, false);

        $htmlA = $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.emails.branding.preview'), $this->payload([
                'company_name' => 'شركة ألف',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('شركة ألف', $htmlA);
        $this->assertStringNotContainsString('شركة باء السرية', $htmlA);
        $this->assertStringNotContainsString('email-branding/b.png', $htmlA);
        $this->assertStringContainsString((string) EmailBrandPublicUrl::forWorkspacePath((int) $workspaceA->id, $logoA), $htmlA);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->put(route('workspace.emails.branding.update'), $this->payload([
                'company_name' => 'شركة ألف المحدّثة',
            ]))
            ->assertRedirect();

        $this->assertSame('شركة باء السرية', EmailBranding::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->value('company_name'));
        $this->assertSame('شركة ألف المحدّثة', EmailBranding::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->value('company_name'));

        $this->inWorkspace($workspaceA, function () use ($workspaceB): void {
            $this->assertNull(EmailBranding::query()->where('workspace_id', $workspaceB->id)->first());
            $this->assertSame(1, EmailBranding::query()->count());
        });
    }

    public function test_missing_branding_does_not_fall_back_to_another_workspace(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [, $workspaceB] = $this->createWorkspaceOwner('store');

        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'company_name' => 'هوية مسروقة',
            'primary_color' => '#000000',
            'secondary_color' => '#000000',
            'background_color' => '#F4F6FB',
            'text_color' => '#111827',
        ]);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.emails.branding.edit'))
            ->assertOk()
            ->assertDontSee('هوية مسروقة');

        $account = $this->account($workspaceA, 'a@a.test');
        $html = app(EmailBrandingHtmlBuilder::class)->render(
            app(EmailBrandingHtmlBuilder::class)->forOutbound(
                $this->outbound($account, 'to@example.com'),
                $account,
            )
        );

        $this->assertStringNotContainsString('هوية مسروقة', $html);
        $this->assertSame(2, EmailBranding::withoutGlobalScopes()->count());
        $this->assertTrue(
            EmailBranding::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->exists()
        );
    }

    public function test_branding_table_does_not_store_mailbox_secrets(): void
    {
        $this->assertFalse(Schema::hasColumn('email_brandings', 'password'));
        $this->assertFalse(Schema::hasColumn('email_brandings', 'smtp_host'));
        $this->assertFalse(Schema::hasColumn('email_brandings', 'smtp_username'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'شركة الاختبار',
            'company_description' => 'وصف مختصر',
            'primary_color' => '#0F172A',
            'secondary_color' => '#2563EB',
            'background_color' => '#F4F6FB',
            'text_color' => '#111827',
            'header_style' => 'solid',
            'show_header_logo' => '1',
            'show_header_company_name' => '1',
            'show_footer_company_name' => '1',
            'show_footer_email' => '1',
            'show_footer_phone' => '1',
            'show_footer_website' => '1',
            'show_footer_address' => '1',
            'show_footer_social' => '1',
            'show_footer_copyright' => '1',
            'show_cta' => '0',
        ], $overrides);
    }

    private function assertGmailSafeHtml(string $html): void
    {
        $this->assertStringContainsString('<table role="presentation"', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringNotContainsString('display:flex', $html);
        $this->assertStringNotContainsString('display:grid', $html);
        $this->assertStringNotContainsString('object-fit', $html);
        $this->assertDoesNotMatchRegularExpression('#src="(?!https?://|cid:)#', $html);
    }

    private function account(Workspace $workspace, string $email): EmailAccount
    {
        return EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Mailbox',
            'email' => $email,
            'password' => 'secret',
            'imap_host' => 'imap.mail.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.mail.test',
            'smtp_port' => 587,
            'brand_color' => '#06C2A4',
        ]);
    }

    private function outbound(EmailAccount $account, string $recipient): EmailMessage
    {
        return EmailMessage::withoutGlobalScopes()->create([
            'workspace_id' => $account->workspace_id,
            'email_account_id' => $account->id,
            'sender' => $account->email,
            'recipient' => $recipient,
            'subject' => 'Hello',
            'body' => 'Body',
            'type' => 'outbound',
            'delivery_status' => 'sending',
        ]);
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
            'code' => $workspaceType.'_branding_'.uniqid(),
            'name' => 'Email Branding Plan',
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
