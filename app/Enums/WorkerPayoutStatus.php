<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerPayoutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::Processing => 'قيد المعالجة',
            self::Paid => 'مدفوع',
            self::Failed => 'فشل',
            self::Cancelled => 'ملغى',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Processing, self::Cancelled],
            self::Processing => [self::Paid, self::Failed, self::Cancelled],
            self::Failed => [self::Processing, self::Cancelled],
            self::Paid, self::Cancelled => [],
        }, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled], true);
    }
}
