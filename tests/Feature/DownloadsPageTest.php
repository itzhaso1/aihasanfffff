<?php

namespace Tests\Feature;

use Tests\TestCase;

class DownloadsPageTest extends TestCase
{
    public function test_downloads_hub_is_public_and_lists_chat_only(): void
    {
        $response = $this->get('/downloads');

        $response->assertOk();
        $response->assertSee('حاسم شات', false);
        $response->assertSee(route('downloads.app', 'chat'), false);
        $response->assertDontSee('حاسم المالية', false);
        $response->assertDontSee('حاسم الكاشير', false);
        $response->assertDontSee(route('downloads.app', 'chat').'/finance', false);
        $response->assertDontSee('/downloads/finance', false);
        $response->assertDontSee('/downloads/cashier', false);
        $response->assertDontSee('https://apps.apple.com', false);
        $response->assertDontSee('https://play.google.com', false);
    }

    public function test_chat_app_page_does_not_mix_business_products(): void
    {
        $this->get('/downloads/chat')
            ->assertOk()
            ->assertSee('حاسم شات', false)
            ->assertSee('صندوق وارد موحّد', false)
            ->assertSee('/images/landing-inbox.jpg', false)
            ->assertDontSee('حاسم المالية', false)
            ->assertDontSee('حاسم الكاشير', false);
    }

    public function test_business_app_pages_are_not_found(): void
    {
        $this->get('/downloads/finance')->assertNotFound();
        $this->get('/downloads/cashier')->assertNotFound();
        $this->get('/downloads/unknown')->assertNotFound();
    }

    public function test_download_buttons_render_only_when_urls_exist_for_chat(): void
    {
        config([
            'downloads.apps.chat.google_play_url' => 'https://play.google.com/store/apps/details?id=online.hasim.chat',
            'downloads.apps.chat.app_store_url' => null,
            'downloads.apps.chat.windows_url' => null,
        ]);

        $chat = $this->get('/downloads/chat')->assertOk();
        $chat->assertSee('https://play.google.com/store/apps/details?id=online.hasim.chat', false);
        $chat->assertSee('تحميل من Google Play', false);
        $chat->assertDontSee('تحميل لـ Windows', false);
        $chat->assertDontSee('تحميل من App Store', false);
    }

    public function test_landing_navbar_links_to_downloads(): void
    {
        $this->get('/')->assertOk()->assertSee(route('downloads'), false);
    }
}
