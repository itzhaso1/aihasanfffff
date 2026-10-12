<?php

declare(strict_types=1);

namespace App\AI\Tools;

final readonly class EmployeeToolGrant
{
    public function __construct(
        public string $name,
        public bool $allowed,
        public ?int $maxCallsPerRun = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw ToolRegistryException::invalid('Tool grant names must be snake_case identifiers.');
        }
        if ($maxCallsPerRun !== null && $maxCallsPerRun < 1) {
            throw ToolRegistryException::invalid('Tool grant max calls must be at least 1.');
        }
    }
}
