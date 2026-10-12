<?php

declare(strict_types=1);

namespace App\AI\Employees;

/**
 * The single source for reply length. Output token budgets derive from it unless an
 * employee sets an explicit advanced override.
 */
enum EmployeeResponseLength: string
{
    case Short = 'short';
    case Medium = 'medium';
    case Long = 'long';

    public function defaultMaxOutputTokens(): int
    {
        return match ($this) {
            self::Short => 256,
            self::Medium => 512,
            self::Long => 1024,
        };
    }

    public static function fromTokenBudget(int $tokens): self
    {
        return match (true) {
            $tokens <= 320 => self::Short,
            $tokens >= 800 => self::Long,
            default => self::Medium,
        };
    }
}
