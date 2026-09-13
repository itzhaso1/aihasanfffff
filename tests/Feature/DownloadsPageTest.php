<?php

namespace Tests\Feature;

use Tests\TestCase;

class DownloadsPageTest extends TestCase
{
    public function test_downloads_page_is_public_and_uses_existing_assets(): void
    {
        $response = $this->get('/downloads');

        $response->assertOk();
        $response->assertSee('حمّل حاسم أينما كنت', false);
        $response->assertSee('تحميل التطبيقات', false);
        $response->assertSee('حاسم للمحادثات', false);
        $response->assertSee('حاسم للفوترة', false);
        $response->assertSee('حاسم للكاشير', false);
        $response->assertSee('/images/landing-inbox.jpg', false);
        $response->assertSee('/images/landing-invoicing.jpg', false);
        $response->assertSee('/images/landing-cashier.jpg', false);
        $response->assertDontSee('https://apps.apple.com', false);
        $response->assertDontSee('https://play.google.com', false);
    }

    public function test_landing_navbar_links_to_downloads(): void
    {
        $this->get('/')->assertOk()->assertSee(route('downloads'), false);
    }
}
