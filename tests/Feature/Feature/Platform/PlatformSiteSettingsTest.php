<?php

namespace Tests\Feature\Feature\Platform;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Support\Site\PublicSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformSiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_site_settings(): void
    {
        $this->get('/platform/site')->assertRedirect(route('platform.login'));
    }

    public function test_regular_user_cannot_open_site_settings(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/platform/site')
            ->assertRedirect(route('platform.login'));
    }

    public function test_platform_admin_can_update_public_site_identity_and_download_link(): void
    {
        Storage::fake('public');
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->get(route('platform.site.edit'))
            ->assertOk()
            ->assertSee('الموقع العام', false)
            ->assertSee('الاسم الكامل بالعربية', false)
            ->assertSee('شعار الموقع', false)
            ->assertSee('أيقونة التبويب', false)
            ->assertSee('حاسم شات', false);

        $this->actingAs($admin, 'platform_admin')
            ->put(route('platform.site.update'), [
                'name_ar' => 'شركة التجربة',
                'name_en' => 'TRIAL SMART',
                'app_name' => 'TRIAL',
                'tagline' => 'TRIAL SMART',
                'description' => 'وصف تجريبي للموقع العام.',
                'footer_company' => 'شركة التجربة للتجارة',
                'phone' => '+966 50 000 0000',
                'commercial_registration' => '1234567890',
                'address' => 'الرياض، حي تجريبي',
                'email' => 'hello@example.com',
                'tax_number' => '300000000000003',
                'chat_google_play_url' => 'https://play.google.com/store/apps/details?id=online.hasim.chat',
                'logo' => $this->fakePngUpload('brand.png'),
            ])
            ->assertRedirect(route('platform.site.edit'));

        PublicSite::flush();

        $home = $this->get('/')->assertOk();
        $home->assertSee('شركة التجربة', false);
        $home->assertSee('TRIAL SMART', false);
        $home->assertSee('وصف تجريبي للموقع العام.', false);
        $home->assertSee('الرياض، حي تجريبي', false);
        $home->assertSee('300000000000003', false);
        $home->assertSee('/storage/platform/site/', false);

        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('شركة التجربة', false)
            ->assertSee('1234567890', false)
            ->assertSee('hello@example.com', false)
            ->assertSee('+966 50 000 0000', false)
            ->assertSee('300000000000003', false);

        $this->get('/downloads/chat')
            ->assertOk()
            ->assertSee('https://play.google.com/store/apps/details?id=online.hasim.chat', false);

        $this->get('/downloads/finance')
            ->assertOk()
            ->assertDontSee('https://play.google.com/store/apps/details?id=online.hasim.chat', false);
    }

    public function test_empty_optional_contact_fields_are_not_invented_on_legal_pages(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->put(route('platform.site.update'), [
                'name_ar' => 'حاسم الذكي',
                'name_en' => 'HASIM SMART',
                'app_name' => 'HASEM',
                'tagline' => 'HASIM SMART',
                'description' => 'منصة عربية لإدارة المحادثات والطلبات والعملاء والفريق من مكان واحد.',
                'footer_company' => 'شركة حاسم الذكي',
                'phone' => '+966 59 539 7059',
                'commercial_registration' => '7054911743',
                'address' => 'الرياض، حي المونسية، طريق الأمير محمد بن سلمان بن عبدالعزيز',
                'email' => '',
                'tax_number' => '',
            ])
            ->assertRedirect(route('platform.site.edit'));

        PublicSite::flush();

        $html = $this->get(route('legal.privacy'))->assertOk()->getContent();
        $this->assertNull(PublicSite::current()->email());
        $this->assertNull(PublicSite::current()->taxNumber());
        $this->assertStringNotContainsString('<dt>البريد الإلكتروني</dt>', $html);
        $this->assertStringNotContainsString('<dt>الرقم الضريبي</dt>', $html);
        $this->assertStringNotContainsString('hello@example.com', $html);
        $this->assertStringNotContainsString('example@email', $html);
        $this->assertStringNotContainsString('admin@hasem.local', $html);
    }

    private function fakePngUpload(string $name): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertNotFalse($png);

        return UploadedFile::fake()->createWithContent($name, $png);
    }
}
