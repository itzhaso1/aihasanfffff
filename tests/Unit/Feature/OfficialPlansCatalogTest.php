<?php

namespace Tests\Unit\Feature;

use App\Models\Plan;
use App\Services\Subscription\SubscriptionService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficialPlansCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_plans_returns_exactly_four_official_codes(): void
    {
        $this->seed(FoundationSeeder::class);

        $plans = app(SubscriptionService::class)->availablePlans('company');
        $codes = $plans->pluck('code')->sort()->values()->all();

        $this->assertSame(['business', 'enterprise', 'pro', 'starter'], $codes);
        $this->assertCount(4, $plans);
        $this->assertTrue($plans->every(fn (Plan $plan) => (bool) $plan->is_official));
        $this->assertTrue($plans->every(fn (Plan $plan) => (bool) $plan->is_public));
    }

    public function test_legacy_company_pro_not_in_catalog_when_not_public(): void
    {
        $this->seed(FoundationSeeder::class);

        $legacy = Plan::query()->where('code', 'company_pro')->first();
        $this->assertNotNull($legacy);
        $this->assertFalse((bool) $legacy->is_public);
        $this->assertFalse((bool) $legacy->is_official);

        $codes = app(SubscriptionService::class)->availablePlans('company')->pluck('code')->all();
        $this->assertNotContains('company_pro', $codes);
    }
}
