<?php

declare(strict_types=1);

namespace App\AI\Training;

enum TrainingPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public function weight(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Normal => 2,
            self::Low => 1,
        };
    }
}
