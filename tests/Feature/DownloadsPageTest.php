<?php

namespace Tests\Feature;

use Tests\TestCase;

class DownloadsPageTest extends TestCase
{
    public function test_downloads_hub_is_public_and_uses_existing_assets(): void
    {
        $response = $this->get('/downloads');

        $response->assertOk();
        $response->assertSee('حمّل التطبيق المناسب لعملك', false);
        $response->assertSee('تحميل التطبيقات', false);
        $response->assertSee('حاسم شات', false);
        $response->assertSee('حاسم المالية', false);
        $response->assertSee('حاسم الكاشير', false);
        $response->assertSee('عرض التطبيق', false);
        $response->assertSee(route('downloads.app', 'chat'), false);
        $response->assertSee(route('downloads.app', 'finance'), false);
        $response->assertSee(route('downloads.app', 'cashier'), false);
        $response->assertSee('/images/landing-inbox.jpg', false);
        $response->assertSee('/images/landing-invoicing.jpg', false);
        $response->assertSee('/images/landing-cashier.jpg', false);
        $response->assertDontSee('https://apps.apple.com', false);
        $response->assertDontSee('https://play.google.com', false);
        $response->assertDontSee('تحميل من App Store', false);
        $response->assertDontSee('تحميل من Google Play', false);
    }

    public function test_each_app_has_its_own_page_without_mixing_products(): void
    {
        $this->get('/downloads/chat')
            ->assertOk()
            ->assertSee('حاسم شات', false)
            ->assertSee('صندوق وارد موحّد', false)
            ->assertSee('/images/landing-inbox.jpg', false)
            ->assertDontSee('حاسم المالية', false)
            ->assertDontSee('حاسم الكاشير', false)
            ->assertDontSee('https://play.google.com', false);

        $this->get('/downloads/finance')
            ->assertOk()
            ->assertSee('حاسم المالية', false)
            ->assertSee('الفواتير والمدفوعات', false)
            ->assertSee('/images/landing-invoicing.jpg', false)
            ->assertDontSee('صندوق وارد موحّد', false)
            ->assertDontSee('حاسم الكاشير', false);

        $this->get('/downloads/cashier')
            ->assertOk()
            ->assertSee('حاسم الكاشير', false)
            ->assertSee('نقطة البيع', false)
            ->assertSee('/images/landing-cashier.jpg', false)
            ->assertDontSee('صندوق وارد موحّد', false)
            ->assertDontSee('حاسم المالية', false);
    }

    public function test_unknown_app_page_is_not_found(): void
    {
        $this->get('/downloads/unknown')->assertNotFound();
    }

    public function test_download_buttons_render_only_when_urls_exist_for_that_app(): void
    {
        config([
            'downloads.apps.chat.google_play_url' => 'https://play.google.com/store/apps/details?id=online.hasim.chat',
            'downloads.apps.chat.app_store_url' => null,
            'downloads.apps.chat.windows_url' => null,
            'downloads.apps.finance.google_play_url' => null,
            'downloads.apps.finance.app_store_url' => null,
            'downloads.apps.finance.windows_url' => 'https://files.hasim.example/finance-setup.exe',
            'downloads.apps.cashier.google_play_url' => null,
            'downloads.apps.cashier.app_store_url' => null,
            'downloads.apps.cashier.windows_url' => null,
        ]);

        $chat = $this->get('/downloads/chat')->assertOk();
        $chat->assertSee('https://play.google.com/store/apps/details?id=online.hasim.chat', false);
        $chat->assertSee('تحميل من Google Play', false);
        $chat->assertDontSee('تحميل لـ Windows', false);
        $chat->assertDontSee('تحميل من App Store', false);

        $finance = $this->get('/downloads/finance')->assertOk();
        $finance->assertSee('https://files.hasim.example/finance-setup.exe', false);
        $finance->assertSee('تحميل لـ Windows', false);
        $finance->assertDontSee('https://play.google.com/store/apps/details?id=online.hasim.chat', false);
        $finance->assertDontSee('تحميل من Google Play', false);

        $cashier = $this->get('/downloads/cashier')->assertOk();
        $cashier->assertSee('روابط التحميل ستظهر هنا عند نشر هذا التطبيق.', false);
        $cashier->assertDontSee('https://play.google.com/store/apps/details?id=online.hasim.chat', false);
        $cashier->assertDontSee('https://files.hasim.example/finance-setup.exe', false);
        $cashier->assertDontSee('تحميل من Google Play', false);
        $cashier->assertDontSee('تحميل لـ Windows', false);
    }

    public function test_landing_navbar_links_to_downloads(): void
    {
        $this->get('/')->assertOk()->assertSee(route('downloads'), false);
    }
}
