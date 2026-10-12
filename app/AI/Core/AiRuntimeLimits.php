<?php

declare(strict_types=1);

namespace App\AI\Core;

use InvalidArgumentException;

final readonly class AiRuntimeLimits
{
    public const MAX_ALLOWED_TOOL_ROUNDS = 10;

    public function __construct(
        public int $maxToolRounds = 3,
    ) {
        if ($maxToolRounds < 0 || $maxToolRounds > self::MAX_ALLOWED_TOOL_ROUNDS) {
            throw new InvalidArgumentException('Tool rounds must be between 0 and '.self::MAX_ALLOWED_TOOL_ROUNDS.'.');
        }
    }
}
