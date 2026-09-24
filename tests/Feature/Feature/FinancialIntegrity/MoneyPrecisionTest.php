<?php

namespace Tests\Feature\Feature\FinancialIntegrity;

use App\Support\Money\Money;
use PHPUnit\Framework\TestCase;

class MoneyPrecisionTest extends TestCase
{
    public function test_rounding_edge_cases(): void
    {
        $this->assertSame('0.10', Money::add('0.05', '0.05'));
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('1.01', Money::add('1.00', '0.01'));
        $this->assertSame('2.50', Money::quantityTimesUnitPrice(2.5, 1));
        $this->assertSame('-1.00', Money::sub('0.00', '1.00'));
    }

    public function test_tax_and_discount_percentages_use_integer_cents(): void
    {
        $this->assertSame('0.15', Money::percentOf('1.00', 15));
        $this->assertSame('13.04', Money::extractInclusiveTax('100.00', 15));
    }
}
