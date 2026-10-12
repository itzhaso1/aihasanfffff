<?php

namespace Tests\Feature;

use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPlanCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_cards_use_arabic_customer_highlights_not_internal_keys(): void
    {
        $this->seed(FoundationSeeder::class);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('STARTER', $html);
        $this->assertStringContainsString('المبتدئة', $html);
        $this->assertStringContainsString('الاحترافية', $html);
        $this->assertStringContainsString('الأعمال', $html);
        $this->assertStringContainsString('المؤسسات', $html);
        $this->assertStringContainsString('99.00', $html);
        $this->assertStringContainsString('199.00', $html);
        $this->assertStringContainsString('349.00', $html);
        $this->assertStringContainsString('499.00', $html);

        $this->assertStringContainsString('إدارة العملاء', $html);
        $this->assertStringContainsString('لوحة تحكم أساسية', $html);
        $this->assertStringContainsString('استخدام محدود للمزايا', $html);
        $this->assertStringContainsString('كل مميزات المبتدئة', $html);
        $this->assertStringContainsString('تقارير متقدمة', $html);
        $this->assertStringContainsString('المنتجات والمخزون', $html);
        $this->assertStringContainsString('طلبات التجارة', $html);
        $this->assertStringContainsString('مزايا الذكاء الاصطناعي', $html);
        $this->assertStringContainsString('صلاحيات الموظفين', $html);
        $this->assertStringNotContainsString('إدارة المخزون', $html);
        $this->assertStringNotContainsString('إدارة المصروفات', $html);
        $this->assertStringNotContainsString('تقارير مالية متقدمة', $html);
        $this->assertStringContainsString('كل مميزات الأعمال', $html);
        $this->assertStringContainsString('صلاحيات متقدمة', $html);
        $this->assertStringContainsString('استخدام مرن حسب الاحتياج', $html);

        $this->assertStringNotContainsString('>dashboard<', $html);
        $this->assertStringNotContainsString('>customers<', $html);
        $this->assertStringNotContainsString('>subscription<', $html);
        $this->assertStringNotContainsString('>usage<', $html);
        $this->assertStringNotContainsString('إدارة الفروع', $html);
        $this->assertStringNotContainsString('أتمتة الأعمال', $html);
    }
}
