<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerWorkSessionSegmentType: string
{
    case Active = 'active';

    case Break = 'break';

    case Idle = 'idle';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'عمل',
            self::Break => 'استراحة',
            self::Idle => 'خمول',
        };
    }

    public function canFollow(self $previous): bool
    {
        return match ($previous) {
            self::Active => in_array($this, [self::Break, self::Idle], true),
            self::Break, self::Idle => $this === self::Active,
        };
    }
}
