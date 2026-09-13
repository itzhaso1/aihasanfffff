<?php

namespace App\Services\EInvoicing;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\Xml\DocumentUuid;
use App\EInvoicing\Xml\GeneratedEInvoiceXml;
use App\EInvoicing\Xml\UblMapper;
use App\EInvoicing\Xml\UblNamespaces;
use App\EInvoicing\Xml\Validation\XmlValidator;
use DOMDocument;

/**
 * Application service: EInvoiceDocument → UBL XML → local validation.
 *
 * Safe to call from web, jobs, or a future API. No HTTP, no controllers.
 */
class EInvoiceXmlGenerator
{
    public function __construct(
        private readonly UblMapper $mapper = new UblMapper,
        private readonly XmlValidator $validator = new XmlValidator,
    ) {}

    public function generate(EInvoiceDocument $document, bool $validate = false): GeneratedEInvoiceXml
    {
        $xml = $this->mapper->map($document);
        $parsed = new DOMDocument;
        $parsed->loadXML($xml);
        $root = $parsed->documentElement;
        $errors = [];
        $schemaValid = true;

        if ($validate) {
            $result = $this->validator->validate($xml);
            $errors = array_merge($result['xsd'], $result['schematron']);
            $schemaValid = $errors === [];
        }

        $uuidNodes = $parsed->getElementsByTagNameNS(UblNamespaces::CBC, 'UUID');

        return new GeneratedEInvoiceXml(
            xml: $xml,
            documentKind: $document->kind(),
            rootLocalName: (string) $root?->localName,
            rootNamespace: (string) $root?->namespaceURI,
            workspaceId: $document->workspaceId,
            sourceSnapshotId: $document->sourceSnapshotId,
            sourceType: $document->sourceType,
            sourceId: $document->sourceId,
            documentNumber: $document->documentNumber,
            documentUuid: $uuidNodes->item(0)?->textContent ?: DocumentUuid::fromDocument($document),
            schemaValid: $schemaValid,
            validationErrors: $errors,
        );
    }
}
