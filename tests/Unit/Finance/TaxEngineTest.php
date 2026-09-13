<?php

namespace Tests\Unit\Finance;

use App\Enums\Finance\TaxPriceMode;
use App\Enums\Finance\TaxProfileType;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\Tax\TaxCalculationException;
use App\Services\Finance\Tax\TaxCalculationService;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_percent_and_quantity_helpers_use_integer_cents(): void
    {
        $this->assertSame('15.00', Money::percentOf('100.00', 15));
        $this->assertSame('25.00', Money::quantityTimesUnitPrice(2.5, 10));
        $this->assertSame('13.33', Money::quantityTimesUnitPrice(1.333, 10));
        $this->assertSame('15.00', Money::extractInclusiveTax('115.00', 15));
        $this->assertSame('0.00', Money::percentOf('0.01', 15));
    }

    public function test_facade_line_calculator_keeps_float_shape(): void
    {
        $calculator = app(TaxCalculationService::class);
        $standard = $calculator->calculateAmount(1000, 'standard', 15);
        $exempt = $calculator->calculateAmount(1000, 'exempt', 15);

        $this->assertSame(1000.0, $standard['taxable_amount']);
        $this->assertSame(150.0, $standard['tax_amount']);
        $this->assertSame(1150.0, $standard['total']);
        $this->assertSame(0.0, $exempt['tax_amount']);
        $this->assertSame(1000.0, $exempt['total']);

        $line = $calculator->calculateLine(1, 100, 0, 'standard', 15);
        $this->assertSame(100.0, $line['taxable_amount']);
        $this->assertSame(15.0, $line['tax_amount']);
        $this->assertSame(115.0, $line['total']);
    }

    public function test_standard_zero_rated_exempt_and_out_of_scope_are_distinct(): void
    {
        [$workspace] = $this->workspace();
        $calculator = app(TaxCalculationService::class);

        $result = $calculator->calculateDocument($workspace, [
            ['product_name' => 'A', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0, 'tax_type' => 'standard', 'tax_rate' => 15],
            ['product_name' => 'B', 'quantity' => 1, 'unit_price' => 40, 'discount' => 0, 'tax_type' => 'zero_rated', 'tax_rate' => 0],
            ['product_name' => 'C', 'quantity' => 1, 'unit_price' => 30, 'discount' => 0, 'tax_type' => 'exempt', 'tax_rate' => 15],
            ['product_name' => 'D', 'quantity' => 1, 'unit_price' => 20, 'discount' => 0, 'tax_type' => 'out_of_scope'],
        ]);

        $this->assertSame(190.0, $result->subtotal);
        $this->assertSame(190.0, $result->taxableAmount);
        $this->assertSame(15.0, $result->taxTotal);
        $this->assertSame(205.0, $result->grandTotal);
        $this->assertSame(TaxProfileType::ZeroRated, $result->lines[1]->classification);
        $this->assertSame(0.0, $result->lines[1]->taxRate);
        $this->assertSame(TaxProfileType::Exempt, $result->lines[2]->classification);
        $this->assertSame(0.0, $result->lines[2]->taxAmount);
        $this->assertSame(TaxProfileType::OutOfScope, $result->lines[3]->classification);
        $this->assertCount(4, $result->categoryTotals);
    }

    public function test_inclusive_standard_extracts_tax_from_gross(): void
    {
        [$workspace] = $this->workspace();
        $result = app(TaxCalculationService::class)->calculateDocument(
            $workspace,
            [['product_name' => 'Incl', 'quantity' => 1, 'unit_price' => 115, 'discount' => 0, 'tax_type' => 'standard', 'tax_rate' => 15]],
            TaxPriceMode::Inclusive,
        );

        $this->assertSame(100.0, $result->taxableAmount);
        $this->assertSame(15.0, $result->taxTotal);
        $this->assertSame(115.0, $result->grandTotal);
        $this->assertSame(115.0, $result->lines[0]->total);
    }

    public function test_discount_is_capped_and_negative_inputs_are_rejected(): void
    {
        [$workspace] = $this->workspace();
        $calculator = app(TaxCalculationService::class);

        $capped = $calculator->calculateDocument($workspace, [[
            'product_name' => 'Cap',
            'quantity' => 1,
            'unit_price' => 50,
            'discount' => 80,
            'tax_type' => 'standard',
            'tax_rate' => 15,
        ]]);
        $this->assertSame(50.0, $capped->discountTotal);
        $this->assertSame(0.0, $capped->taxableAmount);
        $this->assertSame(0.0, $capped->taxTotal);

        $this->expectException(TaxCalculationException::class);
        $calculator->calculateDocument($workspace, [[
            'product_name' => 'Neg',
            'quantity' => 1,
            'unit_price' => 50,
            'discount' => -1,
            'tax_type' => 'standard',
            'tax_rate' => 15,
        ]]);
    }

    public function test_standard_zero_rate_and_invalid_classification_are_rejected(): void
    {
        [$workspace] = $this->workspace();
        $calculator = app(TaxCalculationService::class);

        try {
            $calculator->calculateDocument($workspace, [[
                'product_name' => 'Zero',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_type' => 'standard',
                'tax_rate' => 0,
            ]]);
            $this->fail('standard @ 0 should be rejected');
        } catch (TaxCalculationException) {
            $this->assertTrue(true);
        }

        $this->expectException(TaxCalculationException::class);
        $calculator->calculateDocument($workspace, [[
            'product_name' => 'Bad',
            'quantity' => 1,
            'unit_price' => 100,
            'discount' => 0,
            'tax_type' => 'not_a_type',
            'tax_rate' => 15,
        ]]);
    }

    public function test_negative_rate_is_rejected(): void
    {
        [$workspace] = $this->workspace();
        $this->expectException(TaxCalculationException::class);
        app(TaxCalculationService::class)->calculateDocument($workspace, [[
            'product_name' => 'NegRate',
            'quantity' => 1,
            'unit_price' => 100,
            'discount' => 0,
            'tax_type' => 'standard',
            'tax_rate' => -1,
        ]]);
    }

    /**
     * @return array{0: Workspace, 1: User}
     */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'finance'],
            ['workspace_id' => $workspace->id, 'feature_key' => 'finance', 'enabled' => true, 'source' => 'manual']
        );
        $plan = Plan::query()->where('workspace_type', 'company')->where('is_active', true)->orderByDesc('price')->first();
        if ($plan) {
            Subscription::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        }

        return [$workspace, $user];
    }
}
