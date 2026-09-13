<?php

namespace App\EInvoicing\Xml\Validation;

/**
 * Local XML validation boundary. Does not call ZATCA.
 */
final class XmlValidator
{
    public function __construct(
        private readonly XsdValidator $xsdValidator = new XsdValidator,
        private readonly SchematronValidator $schematronValidator = new SchematronValidator,
    ) {}

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
