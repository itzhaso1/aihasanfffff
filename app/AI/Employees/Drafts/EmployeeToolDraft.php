<?php

declare(strict_types=1);

namespace App\AI\Employees\Drafts;

use App\AI\Employees\InvalidEmployeeData;

final readonly class EmployeeToolDraft
{
    public const MAX_CALLS_PER_RUN = 20;

    public function __construct(
        public string $name,
        public bool $allowed = true,
        public ?int $maxCallsPerRun = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw InvalidEmployeeData::field('tools', 'Tool names must be snake_case identifiers.');
        }
        if ($maxCallsPerRun !== null && ($maxCallsPerRun < 1 || $maxCallsPerRun > self::MAX_CALLS_PER_RUN)) {
            throw InvalidEmployeeData::field('tools.max_calls_per_run', 'Calls per run are out of range.');
        }
    }
}
