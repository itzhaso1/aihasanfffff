<?php

namespace Tests\Unit\Money;

use App\Support\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_adds_without_floating_point_drift(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('230.00', Money::add('200.00', '30.00'));
    }

    public function test_multiply_quantity_and_cost(): void
    {
        $this->assertSame('80.00', Money::mul('40.00', '2'));
        $this->assertSame('25.50', Money::mul('10.20', '2.5'));
        $this->assertSame('15.00', Money::percentOf('100.00', 15));
        $this->assertSame('25.00', Money::quantityTimesUnitPrice(2.5, 10));
        $this->assertSame('15.00', Money::extractInclusiveTax('115.00', 15));
    }

    public function test_rejects_invalid_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('abc');
    }
}
