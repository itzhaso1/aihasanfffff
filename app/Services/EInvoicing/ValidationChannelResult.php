<?php

namespace App\Services\EInvoicing;

use App\Enums\EInvoicing\ValidationChannelStatus;

final readonly class ValidationChannelResult
{
    /**
     * @param  list<ComplianceIssue>  $issues
     */
    public function __construct(
        public ValidationChannelStatus $status,
        public array $issues = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'issues' => array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $this->issues),
        ];
    }
}
