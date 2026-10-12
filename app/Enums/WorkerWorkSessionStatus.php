<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerWorkSessionStatus: string
{
    case Started = 'started';

    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Started => 'جارية',
            self::Ended => 'منتهية',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Started => $next === self::Ended,
            self::Ended => false,
        };
    }
}
