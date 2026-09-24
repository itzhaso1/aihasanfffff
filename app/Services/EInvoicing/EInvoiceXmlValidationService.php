<?php

namespace App\Services\EInvoicing;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\Xml\Validation\XmlValidator;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\Finance\IssuedDocumentSnapshot;

/**
 * Validation layer: snapshot/document → UBL XML string → structured validation result.
 *
 * Does not mutate invoices, issue state, or ZATCA/FATOORA integration.
 */
final class EInvoiceXmlValidationService
{
    public function __construct(
        private readonly EInvoiceFactory $factory,
        private readonly EInvoiceXmlGenerator $xmlGenerator,
        private readonly XmlValidator $xmlValidator,
        private readonly EInvoiceValidationPersistenceService $persistence,
    ) {}

    public function assessDocument(EInvoiceDocument $document, ?string $dataSource = 'snapshot'): ?XmlValidationAssessment
    {
        try {
            $generated = $this->xmlGenerator->generate($document);
        } catch (\App\EInvoicing\Xml\EInvoiceXmlMappingException) {
            return null;
        }

        return $this->assessXml($generated->xml, $dataSource);
    }

    public function assessSnapshot(IssuedDocumentSnapshot $snapshot): ?XmlValidationAssessment
    {
        $document = $this->factory->make($snapshot);

        return $this->assessDocument($document, 'snapshot');
    }

    public function assessXml(string $xml, ?string $dataSource = null): XmlValidationAssessment
    {
        return $this->xmlValidator->assess($xml, $dataSource);
    }

    public function persistIssuedValidation(
        EInvoiceDocumentRecord $record,
        XmlValidationAssessment $assessment,
    ): EInvoiceDocumentRecord {
        return $this->persistence->persist($record, $assessment);
    }

    public function assessmentFromRecord(EInvoiceDocumentRecord $record): ?XmlValidationAssessment
    {
        return $this->persistence->toAssessment($record);
    }
}
