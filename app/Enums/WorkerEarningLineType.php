<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerEarningLineType: string
{
    case Base = 'base';
    case Commission = 'commission';
    case Bonus = 'bonus';
    case Adjustment = 'adjustment';
    case Deduction = 'deduction';

    public function label(): string
    {
        return match ($this) {
            self::Base => 'أساسي',
            self::Commission => 'عمولة',
            self::Bonus => 'مكافأة',
            self::Adjustment => 'تسوية',
            self::Deduction => 'خصم',
        };
    }
}
