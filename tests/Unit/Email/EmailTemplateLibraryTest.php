<?php

namespace Tests\Unit\Email;

use App\Support\Email\EmailTemplateLibrary;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class EmailTemplateLibraryTest extends TestCase
{
    public function test_central_send_catalog_is_unchanged_and_separate_from_the_library(): void
    {
        $this->assertSame('general_notification', config('email_templates.default_template'));
        $this->assertSame('emails.templates.welcome', config('email_templates.templates.welcome.view'));
        $this->assertSame('emails.templates.workspace-branded', config('email_templates.templates.workspace_branded.view'));
        $this->assertArrayNotHasKey('library', config('email_templates.templates'));
    }

    public function test_current_workspace_template_stays_the_live_generic_entry(): void
    {
        $current = EmailTemplateLibrary::get('generic_workspace_branded');

        $this->assertNotNull($current);
        $this->assertSame('emails.workspace-branded', $current['view']);
        $this->assertSame('generic', $current['category']);
        $this->assertSame('active', $current['status']);
        $this->assertTrue(View::exists('emails.workspace-branded'));
        $this->assertContains('companyName', EmailTemplateLibrary::variables('generic_workspace_branded'));
        $this->assertContains('logoUrl', EmailTemplateLibrary::variables('generic_workspace_branded'));
    }

    public function test_library_lists_categories_and_independent_placeholder_views(): void
    {
        $categories = EmailTemplateLibrary::categories();
        $this->assertArrayHasKey('construction', $categories);
        $this->assertArrayHasKey('ecommerce', $categories);
        $this->assertArrayHasKey('generic', $categories);
        $this->assertCount(19, $categories);

        $construction = EmailTemplateLibrary::all('construction');
        $this->assertArrayHasKey('construction_welcome', $construction);
        $this->assertSame('emails.library.construction.welcome', $construction['construction_welcome']['view']);
        $this->assertSame('active', $construction['construction_welcome']['status']);
        $this->assertSame('#0f766e', $construction['construction_welcome']['defaults']['primaryColor']);
        $this->assertSame('draft', $construction['construction_notification']['status']);
        $this->assertContains('primaryColor', EmailTemplateLibrary::variables('construction_welcome'));
        $this->assertContains('secondaryColor', EmailTemplateLibrary::variables('construction_welcome'));
        $this->assertContains('backgroundColor', EmailTemplateLibrary::variables('construction_welcome'));
        $this->assertContains('textColor', EmailTemplateLibrary::variables('construction_welcome'));
        $this->assertContains('logoUrl', EmailTemplateLibrary::variables('construction_welcome'));

        foreach (EmailTemplateLibrary::all() as $template) {
            $this->assertTrue(View::exists($template['view']), $template['key'].' view is missing');
        }

        $constructionSource = (string) file_get_contents(resource_path('views/emails/library/construction/welcome.blade.php'));
        $this->assertStringContainsString('Key: construction_welcome', $constructionSource);
        $this->assertStringContainsString('$primaryColor', $constructionSource);
        $this->assertStringNotContainsString('emails.library.technology.welcome', $constructionSource);
        $this->assertStringContainsString('<!DOCTYPE html>', View::make('emails.library.construction.welcome')->render());
    }
}
