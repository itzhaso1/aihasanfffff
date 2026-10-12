<?php

namespace Tests\Unit\Email;

use App\Support\Email\EmailBrandInlineImages;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailBrandInlineImagesTest extends TestCase
{
    public function test_it_embeds_only_owned_workspace_logo_and_rewrites_to_cid(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('workspaces/7/email-branding/mark.png', 'img');
        Storage::disk('public')->put('workspaces/8/email-branding/other.png', 'secret');

        $this->assertNotNull(EmailBrandInlineImages::logoAbsolutePath(7, 'workspaces/7/email-branding/mark.png'));
        $this->assertNull(EmailBrandInlineImages::logoAbsolutePath(7, 'workspaces/8/email-branding/other.png'));
        $this->assertNull(EmailBrandInlineImages::logoAbsolutePath(7, '../secrets.png'));

        $view = EmailBrandInlineImages::withCids([
            'logoUrl' => 'http://localhost/storage/workspaces/7/email-branding/mark.png',
            'socialLinks' => [
                ['label' => 'LinkedIn', 'url' => 'https://linkedin.com/company/horizon', 'iconUrl' => 'http://localhost/images/email-social/linkedin.png'],
            ],
        ], 7, 'workspaces/7/email-branding/mark.png');

        $this->assertSame('cid:workspace-logo', $view['logoUrl']);
        $this->assertSame('cid:social-linkedin', $view['socialLinks'][0]['iconUrl']);

        $embeds = EmailBrandInlineImages::embeds(7, 'workspaces/7/email-branding/mark.png', $view['socialLinks']);
        $this->assertSame(['workspace-logo', 'social-linkedin'], array_column($embeds, 'cid'));
        $this->assertSame([], EmailBrandInlineImages::embeds(7, 'workspaces/8/email-branding/other.png', []));
    }
}
