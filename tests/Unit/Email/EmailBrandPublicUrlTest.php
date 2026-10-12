<?php

namespace Tests\Unit\Email;

use App\Support\Email\EmailBrandPublicUrl;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailBrandPublicUrlTest extends TestCase
{
    public function test_it_builds_absolute_public_url_only_for_owned_paths(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('workspaces/7/email-branding/mark.png', 'img');
        Storage::disk('public')->put('workspaces/8/email-branding/other.png', 'img');

        $url = EmailBrandPublicUrl::forWorkspacePath(7, 'workspaces/7/email-branding/mark.png');

        $this->assertNotNull($url);
        $this->assertTrue(EmailBrandPublicUrl::isSafeRemoteUrl($url));
        $this->assertMatchesRegularExpression('#^https?://[^/]+/storage/workspaces/7/email-branding/mark\.png$#', $url);
        $this->assertNull(EmailBrandPublicUrl::forWorkspacePath(7, 'workspaces/8/email-branding/other.png'));
        $this->assertNull(EmailBrandPublicUrl::forWorkspacePath(7, '/storage/workspaces/7/email-branding/mark.png'));
        $this->assertNull(EmailBrandPublicUrl::forWorkspacePath(7, 'C:\\logos\\mark.png'));
        $this->assertNull(EmailBrandPublicUrl::forWorkspacePath(7, '../secrets.png'));
        $this->assertFalse(EmailBrandPublicUrl::isSafeRemoteUrl('/storage/workspaces/7/email-branding/mark.png'));
        $this->assertFalse(EmailBrandPublicUrl::isSafeRemoteUrl('file:///tmp/mark.png'));
    }
}
