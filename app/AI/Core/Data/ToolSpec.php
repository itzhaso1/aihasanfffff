<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

use InvalidArgumentException;

final readonly class ToolSpec
{
    /**
     * @param  array<string, mixed>  $inputSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Tool names must be snake_case identifiers.');
        }
    }
}
