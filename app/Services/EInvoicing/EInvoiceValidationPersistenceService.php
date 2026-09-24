<?php

namespace App\Services\EInvoicing;

use App\Enums\EInvoicing\ValidationChannelStatus;
use App\Models\EInvoicing\EInvoiceDocumentRecord;

final class EInvoiceValidationPersistenceService
{
    public function persist(EInvoiceDocumentRecord $record, XmlValidationAssessment $assessment): EInvoiceDocumentRecord
    {
        $record->recordXmlValidation($assessment);

        return $record->fresh();
    }

    public function toAssessment(EInvoiceDocumentRecord $record): ?XmlValidationAssessment
    {
        if ($record->validated_at === null) {
            return null;
        }

        $findings = is_array($record->validation_findings) ? $record->validation_findings : [];

        return new XmlValidationAssessment(
            wellFormed: (bool) ($record->xml_well_formed ?? false),
            xsd: $this->channelFromStored($record->xsd_validation_status, $findings, 'xsd'),
            schematron: $this->channelFromStored($record->schematron_validation_status, $findings, 'schematron'),
            validatedAt: $record->validated_at?->toIso8601String(),
            dataSource: is_string($record->validation_data_source ?? null) ? $record->validation_data_source : 'snapshot',
        );
    }

    /**
     * @param  array<string, mixed>  $findings
     */
    private function channelFromStored(?string $status, array $findings, string $source): ValidationChannelResult
    {
        $enum = ValidationChannelStatus::tryFrom((string) $status) ?? ValidationChannelStatus::NotAvailable;
        $issues = [];
        foreach ($findings as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['source'] ?? null) !== $source) {
                continue;
            }
            $issues[] = new ComplianceIssue(
                code: (string) ($row['code'] ?? 'validation_failed'),
                field: (string) ($row['field'] ?? 'xml'),
                message: (string) ($row['message'] ?? 'Validation failed.'),
                severity: (string) ($row['severity'] ?? 'error'),
                source: $source,
            );
        }

        return new ValidationChannelResult($enum, $issues);
    }
}
