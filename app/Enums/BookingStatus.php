<?php

declare(strict_types=1);

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';

    /**
     * Active statuses that occupy slot capacity.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [
            self::Pending->value,
            self::Confirmed->value,
            self::Rescheduled->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::Confirmed => 'مؤكد',
            self::Rescheduled => 'تمت إعادة الجدولة',
            self::Cancelled => 'ملغي',
            self::Completed => 'مكتمل',
            self::NoShow => 'لم يحضر',
        };
    }

    public function badgeLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending · قيد الانتظار',
            self::Confirmed => 'Confirmed · مؤكد',
            self::Rescheduled => 'Rescheduled · أُعيد جدولته',
            self::Cancelled => 'Cancelled · ملغي',
            self::Completed => 'Completed · مكتمل',
            self::NoShow => 'No Show · لم يحضر',
        };
    }

    public function isOccupyingCapacity(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::Rescheduled], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Completed, self::NoShow], true);
    }
}
