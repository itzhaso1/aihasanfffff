<?php

namespace Tests\Unit\Feature;

use App\Models\Plan;
use App\Support\Plans\PlanCardHighlights;
use Tests\TestCase;

class PlanCardHighlightsTest extends TestCase
{
    public function test_official_tiers_show_arabic_highlights_that_exist_on_the_plan(): void
    {
        $starter = $this->plan('starter', [
            'dashboard', 'customers', 'subscription', 'usage', 'conversations', 'messages', 'smart_replies', 'ai', 'payments',
        ]);
        $this->assertSame([
            'إدارة العملاء',
            'المحادثات',
            'لوحة تحكم أساسية',
            'استخدام محدود للمزايا',
        ], PlanCardHighlights::labels($starter));

        $pro = $this->plan('pro', [
            'dashboard', 'customers', 'usage', 'ai', 'analytics', 'inventory', 'finance',
        ]);
        $this->assertSame([
            'كل مميزات المبتدئة',
            'تقارير متقدمة',
            'إدارة المخزون',
            'إدارة المصروفات',
            'مزايا الذكاء الاصطناعي',
            'استخدام أعلى',
        ], PlanCardHighlights::labels($pro));

        $business = $this->plan('business', [
            'finance', 'analytics', 'inventory', 'employees', 'roles_permissions', 'advanced_customers', 'usage',
        ]);
        $this->assertSame([
            'كل مميزات الاحترافية',
            'صلاحيات الموظفين',
            'تقارير مالية متقدمة',
            'إدارة العملاء المتقدمة',
            'استخدام أعلى',
        ], PlanCardHighlights::labels($business));

        $enterprise = $this->plan('enterprise', [
            'roles_permissions', 'advanced_customers', 'usage', 'white_label',
        ]);
        $this->assertSame([
            'كل مميزات الأعمال',
            'صلاحيات متقدمة',
            'استخدام مرن حسب الاحتياج',
        ], PlanCardHighlights::labels($enterprise));
    }

    public function test_missing_plan_features_are_not_shown(): void
    {
        $starterWithoutCustomers = $this->plan('starter', ['dashboard', 'usage']);

        $this->assertSame([
            'لوحة تحكم أساسية',
            'استخدام محدود للمزايا',
        ], PlanCardHighlights::labels($starterWithoutCustomers));
        $this->assertNotContains('إدارة الفروع', PlanCardHighlights::labels($this->plan('business', ['finance', 'analytics', 'usage'])));
        $this->assertNotContains('أتمتة الأعمال', PlanCardHighlights::labels($this->plan('business', ['finance', 'analytics', 'usage'])));
    }

    /**
     * @param  list<string>  $features
     */
    private function plan(string $code, array $features): Plan
    {
        $plan = new Plan;
        $plan->code = $code;
        $plan->tier = $code;
        $plan->features = $features;

        return $plan;
    }
}
