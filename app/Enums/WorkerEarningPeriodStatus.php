<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerEarningPeriodStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Disputed = 'disputed';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::PendingApproval => 'بانتظار الاعتماد',
            self::Approved => 'معتمد',
            self::Disputed => 'محل اعتراض',
            self::Paid => 'مدفوع',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::PendingApproval],
            self::PendingApproval => [self::Approved, self::Disputed],
            self::Approved => [self::Disputed, self::Paid],
            self::Disputed => [self::PendingApproval],
            self::Paid => [],
        }, true);
    }

    public function isFinalized(): bool
    {
        return in_array($this, [self::Approved, self::Paid], true);
    }
}
