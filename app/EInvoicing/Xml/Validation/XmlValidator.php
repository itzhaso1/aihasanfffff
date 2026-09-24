<?php

namespace App\EInvoicing\Xml\Validation;

use App\Services\EInvoicing\ComplianceIssue;
use App\Services\EInvoicing\ValidationChannelResult;
use App\Services\EInvoicing\XmlValidationAssessment;
use DOMDocument;

/**
 * Local XML validation boundary. Does not call ZATCA.
 */
final class XmlValidator
{
    public function __construct(
        private readonly XsdValidator $xsdValidator = new XsdValidator,
        private readonly SchematronValidator $schematronValidator = new SchematronValidator,
    ) {}

    public function assess(string $xml, ?string $dataSource = null): XmlValidationAssessment
    {
        $document = new DOMDocument;
        $wellFormed = @$document->loadXML($xml);

        $xsd = $wellFormed
            ? $this->xsdValidator->validateStructured($xml)
            : new ValidationChannelResult(
                \App\Enums\EInvoicing\ValidationChannelStatus::Invalid,
                [new ComplianceIssue('xml_not_well_formed', 'xml', 'XML is not well-formed.', source: 'xml')],
            );

        $schematron = $wellFormed
            ? $this->schematronValidator->validateStructured($xml)
            : new ValidationChannelResult(
                \App\Enums\EInvoicing\ValidationChannelStatus::Invalid,
                [new ComplianceIssue('xml_not_well_formed', 'xml', 'XML is not well-formed.', source: 'xml')],
            );

        return new XmlValidationAssessment(
            wellFormed: $wellFormed,
            xsd: $xsd,
            schematron: $schematron,
            validatedAt: now()->toIso8601String(),
            dataSource: $dataSource,
        );
    }

    /**
     * @return array{xsd: list<string>, schematron: list<string>}
     */
    public function validate(string $xml): array
    {
        return [
            'xsd' => $this->xsdValidator->validate($xml),
            'schematron' => $this->schematronValidator->validate($xml),
        ];
    }

    public function xsd(): XsdValidator
    {
        return $this->xsdValidator;
    }

    public function schematron(): SchematronValidator
    {
        return $this->schematronValidator;
    }
}
