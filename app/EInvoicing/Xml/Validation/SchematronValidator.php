<?php

namespace App\EInvoicing\Xml\Validation;

use App\EInvoicing\Xml\EInvoiceXmlValidationException;
use DOMDocument;
use DOMXPath;

/**
 * Local Schematron-style XPath assertions.
 *
 * Official ZATCA Schematron files are not redistributed here. Point
 * E_INVOICE_SCHEMATRON_PATH at a deployment-supplied file when available.
 */
final class SchematronValidator
{
    public function __construct(
        private readonly ?string $rulesPath = null,
    ) {}

    /**
     * @return list<string>
     */
    public function validate(string $xml): array
    {
        $path = $this->rulesFile();
        if ($path === null) {
            throw new EInvoiceXmlValidationException(
                'Schematron rules are not available. See resources/einvoicing/schematron/README.md.'
            );
        }

        $xmlDocument = new DOMDocument;
        if (! @$xmlDocument->loadXML($xml)) {
            return ['XML is not well-formed.'];
        }

        $rules = new DOMDocument;
        if (! @$rules->load($path)) {
            throw new EInvoiceXmlValidationException('Schematron rules file could not be parsed.');
        }

        $xpath = new DOMXPath($xmlDocument);
        $this->registerNamespaces($xpath, $xmlDocument);

        $errors = [];
        foreach ($rules->getElementsByTagName('rule') as $rule) {
            $context = $rule->getAttribute('context') ?: '/*';
            $nodes = @$xpath->query($context);
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                foreach ($rule->getElementsByTagName('assert') as $assert) {
                    $test = $assert->getAttribute('test');
                    if ($test === '') {
                        continue;
                    }

                    $result = @$xpath->evaluate($test, $node);
                    $passed = $result === true
                        || (is_numeric($result) && (int) $result > 0)
                        || ($result instanceof \DOMNodeList && $result->length > 0);

                    if (! $passed) {
                        $errors[] = trim($assert->textContent) ?: $test;
                    }
                }
            }
        }

        return $errors;
    }

    public function rulesFile(): ?string
    {
        $configured = $this->rulesPath ?? env('E_INVOICE_SCHEMATRON_PATH');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $bundled = base_path('resources/einvoicing/schematron/local-structure.sch');

        return is_file($bundled) ? $bundled : null;
    }

    private function registerNamespaces(DOMXPath $xpath, DOMDocument $document): void
    {
        if ($document->documentElement === null) {
            return;
        }

        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        if ($document->documentElement->namespaceURI) {
            $xpath->registerNamespace('ubl', $document->documentElement->namespaceURI);
        }
    }
}
