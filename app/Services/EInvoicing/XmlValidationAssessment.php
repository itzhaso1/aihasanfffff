<?php

namespace App\Services\EInvoicing;

use App\Enums\EInvoicing\ValidationChannelStatus;

/**
 * Structured XML validation outcome (well-formed + XSD + Schematron channels).
 */
final readonly class XmlValidationAssessment
{
    public function __construct(
        public bool $wellFormed,
        public ValidationChannelResult $xsd,
        public ValidationChannelResult $schematron,
        public ?string $validatedAt = null,
        public ?string $dataSource = null,
    ) {}

    public function isFullyValid(): bool
    {
        if (! $this->wellFormed) {
            return false;
        }

        foreach ([$this->xsd, $this->schematron] as $channel) {
            if ($channel->status === ValidationChannelStatus::Invalid) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gate for {@code zatca_requirement = required} issue: XSD must pass when run;
     * official Schematron must not be invalid when configured; unavailable Schematron does not block.
     */
    public function passesRequiredIssueGate(): bool
    {
        if (! $this->wellFormed) {
            return false;
        }

        if ($this->xsd->status !== ValidationChannelStatus::Valid) {
            return false;
        }

        if ($this->schematron->status === ValidationChannelStatus::Invalid) {
            return false;
        }

        return true;
    }

    /**
     * @return list<ComplianceIssue>
     */
    public function allIssues(): array
    {
        $issues = [];
        if (! $this->wellFormed) {
            $issues[] = new ComplianceIssue(
                code: 'xml_not_well_formed',
                field: 'xml',
                message: 'Generated XML is not well-formed.',
                source: 'xml',
            );
        }

        return [...$issues, ...$this->xsd->issues, ...$this->schematron->issues];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'well_formed' => $this->wellFormed,
            'xsd' => $this->xsd->toArray(),
            'schematron' => $this->schematron->toArray(),
            'valid' => $this->isFullyValid(),
            'validated_at' => $this->validatedAt,
            'data_source' => $this->dataSource,
            'issues' => array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $this->allIssues()),
        ];
    }
}
