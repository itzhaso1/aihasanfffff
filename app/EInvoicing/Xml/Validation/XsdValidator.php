<?php

namespace App\EInvoicing\Xml\Validation;

use App\EInvoicing\Xml\EInvoiceXmlValidationException;
use App\EInvoicing\Xml\UblNamespaces;
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
    public function validate(string $xml): array
    {
        $schemaFile = $this->schemaFileFor($xml);
        if ($schemaFile === null) {
            throw new EInvoiceXmlValidationException(
                'UBL 2.1 XSD files are not available. See resources/einvoicing/schemas/README.md.'
            );
        }

        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        if (! @$document->loadXML($xml)) {
            return ['XML is not well-formed.'];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = @$document->schemaValidate($schemaFile);
        $errors = [];
        foreach (libxml_get_errors() as $error) {
            $errors[] = trim($error->message).' (line '.$error->line.')';
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $valid ? [] : ($errors === [] ? ['XSD validation failed.'] : $errors);
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
