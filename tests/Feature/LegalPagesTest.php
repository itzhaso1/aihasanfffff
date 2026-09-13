<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_published_legal_pages_return_ok_with_confirmed_company_facts_only(): void
    {
        $pages = [
            'legal.privacy' => ['سياسة الخصوصية', 'نحرص في حاسم الذكي على حماية خصوصيتك'],
            'legal.terms' => ['الشروط والأحكام', 'باستخدامك لمنصة حاسم الذكي'],
            'legal.cookies' => ['سياسة ملفات تعريف الارتباط', 'ملفات تعريف الارتباط'],
            'legal.acceptable-use' => ['سياسة الاستخدام المقبول', 'استخدام خدمات حاسم الذكي بطريقة آمنة'],
        ];

        foreach ($pages as $route => [$title, $intro]) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee($title, false)
                ->assertSee($intro, false)
                ->assertSee('حاسم الذكي', false)
                ->assertSee('7054911743', false)
                ->assertSee('+966 59 539 7059', false)
                ->assertSee('الرياض، حي المونسية، طريق الأمير محمد بن سلمان بن عبدالعزيز', false);
        }
    }

    public function test_unpublished_refund_page_is_not_presented_as_final_policy(): void
    {
        $this->assertFalse((bool) config('legal.pages.refund'));

        $this->get('/refund')->assertNotFound();
        $this->get(route('legal.refund'))->assertNotFound();
    }

    public function test_public_legal_pages_do_not_expose_placeholders_or_invented_facts(): void
    {
        foreach (['legal.privacy', 'legal.terms', 'legal.cookies', 'legal.acceptable-use'] as $route) {
            $html = $this->get(route($route))->assertOk()->getContent();

            $this->assertStringNotContainsString('[البريد الإلكتروني الرسمي]', $html);
            $this->assertStringNotContainsString('[تاريخ السريان]', $html);
            $this->assertStringNotContainsString('[الرقم الضريبي]', $html);
            $this->assertStringNotContainsString('[مدة الاحتفاظ بالبيانات]', $html);
            $this->assertStringNotContainsString('[الجهة القضائية المختصة]', $html);
            $this->assertStringNotContainsString('[سياسة الاسترجاع]', $html);
            $this->assertStringNotContainsString('[مدة التجربة المجانية]', $html);
            $this->assertStringNotContainsString('example@email', $html);
            $this->assertStringNotContainsString('admin@hasem.local', $html);
            $this->assertStringNotContainsString('TODO', $html);
            $this->assertStringNotContainsString('hs-legal-placeholder', $html);
        }
    }

    public function test_public_pages_have_no_unproven_compliance_or_certification_claims(): void
    {
        foreach (['/', route('legal.privacy'), route('legal.terms'), route('legal.cookies'), route('legal.acceptable-use'), route('register'), route('login'), route('downloads')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('ZATCA certified', $html);
            $this->assertStringNotContainsString('ZATCA approved', $html);
            $this->assertStringNotContainsString('ZATCA compliant', $html);
            $this->assertStringNotContainsString('certified by ZATCA', $html);
            $this->assertStringNotContainsString('PDPL compliant', $html);
            $this->assertStringNotContainsString('Saudi certified', $html);
            $this->assertStringNotContainsString('tax certified', $html);
            $this->assertStringNotContainsString('VAT certified', $html);
            $this->assertStringNotContainsString('e-invoice compliant', $html);
            $this->assertStringNotContainsString('متوافقون مع PDPL', $html);
            $this->assertStringNotContainsString('معتمدون من هيئة الزكاة', $html);
            $this->assertStringNotContainsString('الاعتمادات والربط الرسمي', $html);
            $this->assertStringNotContainsString('البنك المركزي السعودي (ساما)', $html);
        }

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('>ZATCA<', $home);
        $this->assertStringNotContainsString('متوافقة مع متطلبات السوق السعودي', $home);
    }

    public function test_cookie_policy_describes_existing_session_cookies_not_invented_analytics(): void
    {
        $this->get(route('legal.cookies'))
            ->assertOk()
            ->assertSee('جلسة Laravel', false)
            ->assertSee('CSRF', false)
            ->assertSee('pos_guest_', false)
            ->assertSee('لم يُعثر في واجهة الموقع العامة على أدوات إحصائيات تسويقية', false)
            ->assertDontSee('Google Analytics', false);
    }

    public function test_public_footer_and_auth_pages_link_to_published_legal_routes_only(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.terms'), false)
            ->assertSee(route('legal.cookies'), false)
            ->assertSee(route('legal.acceptable-use'), false)
            ->assertDontSee(route('legal.refund'), false);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.terms'), false)
            ->assertSee('بإنشاء الحساب فإنك توافق على', false);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.terms'), false);

        $this->get(route('downloads'))
            ->assertOk()
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.terms'), false)
            ->assertSee(route('legal.cookies'), false)
            ->assertSee(route('legal.acceptable-use'), false)
            ->assertDontSee(route('legal.refund'), false);
    }

    public function test_production_env_does_not_expose_internal_legal_placeholders(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);

        $html = $this->get(route('legal.privacy'))->assertOk()->getContent();
        $this->assertStringNotContainsString('TODO', $html);
        $this->assertStringNotContainsString('[البريد الإلكتروني الرسمي]', $html);
        $this->assertStringNotContainsString('[تاريخ السريان]', $html);
        $this->assertStringNotContainsString('hs-legal-placeholder', $html);
    }
}
