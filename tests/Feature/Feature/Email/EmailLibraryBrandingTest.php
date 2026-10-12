<?php

namespace Tests\Feature\Feature\Email;

use App\Models\EmailBranding;
use App\Models\Workspace;
use App\Support\Email\EmailLibraryBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailLibraryBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_welcome_reads_current_workspace_identity_colors(): void
    {
        $workspace = Workspace::factory()->create();
        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'company_name' => 'أفق المقاولات',
            'primary_color' => '#112233',
            'secondary_color' => '#445566',
            'background_color' => '#EFEFEF',
            'text_color' => '#101010',
            'cta_label' => 'تواصل معنا',
        ]);

        $html = $this->inWorkspace($workspace, fn () => view('emails.library.construction.welcome')->render());

        $this->assertStringContainsString('#112233', $html);
        $this->assertStringContainsString('#445566', $html);
        $this->assertStringContainsString('#EFEFEF', $html);
        $this->assertStringContainsString('#101010', $html);
        $this->assertStringContainsString('أفق المقاولات', $html);
        $this->assertStringContainsString('تواصل معنا', $html);
    }

    public function test_library_welcome_never_reads_another_workspace_branding(): void
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'company_name' => 'شركة ألف',
            'primary_color' => '#111111',
            'secondary_color' => '#222222',
            'background_color' => '#F4F6FB',
            'text_color' => '#111827',
        ]);
        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'company_name' => 'شركة باء السرية',
            'primary_color' => '#ABCDEF',
            'secondary_color' => '#FEDCBA',
            'background_color' => '#123456',
            'text_color' => '#654321',
        ]);

        $html = $this->inWorkspace($workspaceA, fn () => view('emails.library.construction.welcome')->render());

        $this->assertStringContainsString('#111111', $html);
        $this->assertStringContainsString('شركة ألف', $html);
        $this->assertStringNotContainsString('#ABCDEF', $html);
        $this->assertStringNotContainsString('#FEDCBA', $html);
        $this->assertStringNotContainsString('شركة باء السرية', $html);
    }

    public function test_explicit_library_view_data_is_not_overwritten_by_branding(): void
    {
        $workspace = Workspace::factory()->create();
        EmailBranding::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'company_name' => 'أفق المقاولات',
            'primary_color' => '#112233',
            'secondary_color' => '#2563EB',
            'background_color' => '#F4F6FB',
            'text_color' => '#111827',
        ]);

        $html = $this->inWorkspace($workspace, fn () => view('emails.library.construction.welcome', [
            'primaryColor' => '#999999',
        ])->render());

        $this->assertStringContainsString('#999999', $html);
        $this->assertStringNotContainsString('#112233', $html);
    }

    public function test_swatch_expands_short_hex_for_html_color_inputs(): void
    {
        $this->assertSame('#112233', EmailLibraryBranding::swatch('#123', EmailLibraryBranding::DEFAULT_PRIMARY));
        $this->assertSame('#0f172a', EmailLibraryBranding::swatch('not-a-color', EmailLibraryBranding::DEFAULT_PRIMARY));
    }

    public function test_library_uses_builder_color_defaults_without_workspace_context(): void
    {
        $html = view('emails.library.construction.welcome')->render();

        $this->assertStringContainsString(EmailLibraryBranding::DEFAULT_PRIMARY, $html);
        $this->assertStringContainsString(EmailLibraryBranding::DEFAULT_SECONDARY, $html);
        $this->assertStringContainsString(EmailLibraryBranding::DEFAULT_BACKGROUND, $html);
        $this->assertStringContainsString(EmailLibraryBranding::DEFAULT_TEXT, $html);
    }
}
