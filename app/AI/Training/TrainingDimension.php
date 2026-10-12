<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Selector dimensions of `applies_to`, declared in selection precedence order.
 */
enum TrainingDimension: string
{
    case Skills = 'skills';
    case Dialects = 'dialects';
    case Languages = 'languages';
    case Intents = 'intents';
    case Roles = 'roles';
    case Domains = 'domains';

    /** Lower rank is selected earlier when a document matches on several dimensions. */
    public function rank(): int
    {
        return match ($this) {
            self::Skills => 1,
            self::Dialects => 2,
            self::Languages => 3,
            self::Intents => 4,
            self::Roles => 5,
            self::Domains => 6,
        };
    }
}
