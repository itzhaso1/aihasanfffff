<?php

namespace App\Services\EInvoicing;

/**
 * KSA seller or buyer data readiness (distinct from XML/QR artifact flags).
 */
final readonly class PartyDataReadiness
{
    /**
     * @param  list<ComplianceIssue>  $errors
     * @param  list<ComplianceIssue>  $warnings
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public bool $ready,
        public array $errors = [],
        public array $warnings = [],
        public array $fields = [],
    ) {}

    public static function notApplicable(): self
    {
        return new self(ready: true, fields: ['status' => 'not_applicable']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'errors' => array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $this->errors),
            'warnings' => array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $this->warnings),
            'fields' => $this->fields,
        ];
    }
}
