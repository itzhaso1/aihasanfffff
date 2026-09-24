<?php

namespace App\EInvoicing\Xml\Validation;

use App\EInvoicing\Xml\EInvoiceXmlValidationException;
use App\EInvoicing\Xml\UblNamespaces;
use App\Enums\EInvoicing\ValidationChannelStatus;
use App\Services\EInvoicing\ComplianceIssue;
use App\Services\EInvoicing\ValidationChannelResult;
use DOMDocument;

/**
 * Validates generated XML against official OASIS UBL 2.1 XSD files.
 */
final class XsdValidator
{
    public function __construct(
        private readonly ?string $schemaRoot = null,
    ) {}

    /**
     * @return list<string>
     */
    public function validateStructured(string $xml): ValidationChannelResult
    {
        $schemaFile = $this->schemaFileFor($xml);
        if ($schemaFile === null) {
            return new ValidationChannelResult(ValidationChannelStatus::NotAvailable);
        }

        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        if (! @$document->loadXML($xml)) {
            return new ValidationChannelResult(
                ValidationChannelStatus::Invalid,
                [new ComplianceIssue('xml_not_well_formed', 'xml', 'XML is not well-formed.', source: 'xml')],
            );
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = @$document->schemaValidate($schemaFile);
        $issues = [];
        foreach (libxml_get_errors() as $error) {
            $issues[] = new ComplianceIssue(
                code: 'xsd_validation_failed',
                field: 'xml',
                message: trim($error->message).' (line '.$error->line.')',
                source: 'xsd',
            );
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($valid) {
            return new ValidationChannelResult(ValidationChannelStatus::Valid);
        }

        if ($issues === []) {
            $issues[] = new ComplianceIssue('xsd_validation_failed', 'xml', 'XSD validation failed.', source: 'xsd');
        }

        return new ValidationChannelResult(ValidationChannelStatus::Invalid, $issues);
    }

    public function validate(string $xml): array
    {
        $structured = $this->validateStructured($xml);
        if ($structured->status === ValidationChannelStatus::NotAvailable) {
            throw new EInvoiceXmlValidationException(
                'UBL 2.1 XSD files are not available. See resources/einvoicing/schemas/README.md.'
            );
        }

        if ($structured->status === ValidationChannelStatus::Valid) {
            return [];
        }

        return array_map(static fn (ComplianceIssue $issue): string => $issue->message, $structured->issues);
    }

    public function schemaFileFor(string $xml): ?string
    {
        $root = $this->schemaRoot();
        if ($root === null) {
            return null;
        }

        $document = new DOMDocument;
        if (! @$document->loadXML($xml)) {
            return $root.'/maindoc/UBL-Invoice-2.1.xsd';
        }

        $localName = $document->documentElement?->localName;
        $namespace = $document->documentElement?->namespaceURI;

        if ($localName === 'CreditNote' || $namespace === UblNamespaces::CREDIT_NOTE) {
            $path = $root.'/maindoc/UBL-CreditNote-2.1.xsd';

            return is_file($path) ? $path : null;
        }

        $path = $root.'/maindoc/UBL-Invoice-2.1.xsd';

        return is_file($path) ? $path : null;
    }

    public function schemaRoot(): ?string
    {
        $configured = $this->schemaRoot ?? env('E_INVOICE_UBL_XSD_PATH');
        if (is_string($configured) && $configured !== '' && is_dir($configured)) {
            return rtrim($configured, '/');
        }

        $bundled = base_path('resources/einvoicing/schemas/ubl-2.1');

        return is_dir($bundled) ? $bundled : null;
    }
}
