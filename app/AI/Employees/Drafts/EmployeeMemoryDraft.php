<?php

declare(strict_types=1);

namespace App\AI\Employees\Drafts;

final readonly class EmployeeMemoryDraft
{
    public function __construct(
        public bool $conversationMemoryEnabled = true,
        public bool $customerMemoryEnabled = true,
        public bool $commerceContextEnabled = true,
        public bool $relevantHistoryEnabled = true,
    ) {}

    public static function allEnabled(bool $enabled): self
    {
        return new self($enabled, $enabled, $enabled, $enabled);
    }
}
