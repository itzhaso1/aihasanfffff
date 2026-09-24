<?php

namespace App\EInvoicing\Xml\Validation;

use App\EInvoicing\Xml\EInvoiceXmlValidationException;
use App\Enums\EInvoicing\ValidationChannelStatus;
use App\Services\EInvoicing\ComplianceIssue;
use App\Services\EInvoicing\ValidationChannelResult;
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

    public function validateStructured(string $xml): ValidationChannelResult
    {
        $path = $this->rulesFile();
        if ($path === null) {
            return new ValidationChannelResult(ValidationChannelStatus::NotAvailable);
        }

        $xmlDocument = new DOMDocument;
        if (! @$xmlDocument->loadXML($xml)) {
            return new ValidationChannelResult(
                ValidationChannelStatus::Invalid,
                [new ComplianceIssue('xml_not_well_formed', 'xml', 'XML is not well-formed.', source: 'xml')],
            );
        }

        $rules = new DOMDocument;
        if (! @$rules->load($path)) {
            return new ValidationChannelResult(
                ValidationChannelStatus::Invalid,
                [new ComplianceIssue('schematron_rules_unreadable', 'schematron', 'Schematron rules file could not be parsed.', source: 'schematron')],
            );
        }

        $xpath = new DOMXPath($xmlDocument);
        $this->registerNamespaces($xpath, $xmlDocument);

        $issues = [];
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
                        $issues[] = new ComplianceIssue(
                            code: 'schematron_assertion_failed',
                            field: 'xml',
                            message: trim($assert->textContent) ?: $test,
                            source: 'schematron',
                        );
                    }
                }
            }
        }

        return $issues === []
            ? new ValidationChannelResult(ValidationChannelStatus::Valid)
            : new ValidationChannelResult(ValidationChannelStatus::Invalid, $issues);
    }

    /**
     * @return list<string>
     */
    public function validate(string $xml): array
    {
        $structured = $this->validateStructured($xml);
        if ($structured->status === ValidationChannelStatus::NotAvailable) {
            return [];
        }

        if ($structured->status === ValidationChannelStatus::Valid) {
            return [];
        }

        return array_map(static fn (ComplianceIssue $issue): string => $issue->message, $structured->issues);
    }

    public function rulesFile(): ?string
    {
        $configured = $this->rulesPath ?? env('E_INVOICE_SCHEMATRON_PATH');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        return null;
    }

    /**
     * HASEM structural smoke rules — not official ZATCA Schematron.
     */
    public static function localHarnessRulesFile(): ?string
    {
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
