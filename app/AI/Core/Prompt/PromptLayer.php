<?php

declare(strict_types=1);

namespace App\AI\Core\Prompt;

/**
 * Prompt layers in precedence order: a lower value always wins over a higher one.
 */
enum PromptLayer: int
{
    case Constitution = 1;
    case Policy = 2;
    case Employee = 3;
    case Training = 4;
    case Context = 5;
    case UserMessage = 6;

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        $layers = self::cases();
        usort($layers, static fn (self $a, self $b): int => $a->value <=> $b->value);

        return $layers;
    }

    public function key(): string
    {
        return match ($this) {
            self::Constitution => 'constitution',
            self::Policy => 'policy',
            self::Employee => 'employee',
            self::Training => 'training',
            self::Context => 'context',
            self::UserMessage => 'user_message',
        };
    }

    /** Context and the user message are data to reason about, never instructions to follow. */
    public function carriesInstructions(): bool
    {
        return $this->value <= self::Training->value;
    }
}
