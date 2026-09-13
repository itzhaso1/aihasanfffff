<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\SecurityChainException;
use App\EInvoicing\Security\Icv;
use App\EInvoicing\Security\Pih;
use App\EInvoicing\Xml\GeneratedEInvoiceXml;
use App\EInvoicing\Xml\UblNamespaces;
use DOMDocument;
use DOMElement;

/**
 * Injects ICV and PIH into generated UBL XML. Does not live in UblMapper.
 *
 * XML paths follow ZATCA XML Implementation Standard:
 * - ICV: cac:AdditionalDocumentReference / cbc:UUID (BR-KSA-33)
 * - PIH: cac:AdditionalDocumentReference / cac:Attachment / cbc:EmbeddedDocumentBinaryObject (BR-KSA-26)
 */
final class SecurityXmlEnricher
{
    public const ICV_ID = 'ICV';

    public const PIH_ID = 'PIH';

    public function enrich(GeneratedEInvoiceXml $generated, Icv $icv, Pih $pih): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($generated->xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement === null) {
            throw new SecurityChainException(
                'Generated XML could not be parsed for ICV/PIH injection.',
                documentIdentity: $this->identity($generated),
                operation: 'enrich_xml',
                reason: 'malformed_xml',
            );
        }

        $root = $document->documentElement;
        $this->removeExistingSecurityReferences($root);
        $anchor = $this->accountingSupplierParty($root);
        if (! $anchor instanceof DOMElement) {
            throw new SecurityChainException(
                'Generated XML is missing AccountingSupplierParty; ICV/PIH cannot be placed.',
                documentIdentity: $this->identity($generated),
                operation: 'enrich_xml',
                reason: 'missing_supplier_party',
            );
        }

        $icvRef = $this->createIcvReference($document, $icv);
        $pihRef = $this->createPihReference($document, $pih);
        $root->insertBefore($icvRef, $anchor);
        $root->insertBefore($pihRef, $anchor);

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new SecurityChainException(
                'Failed to serialize security-enriched XML.',
                documentIdentity: $this->identity($generated),
                operation: 'enrich_xml',
                reason: 'serialize_failed',
            );
        }

        return $xml;
    }

    private function removeExistingSecurityReferences(DOMElement $root): void
    {
        $toRemove = [];
        foreach ($root->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if ($child->localName !== 'AdditionalDocumentReference') {
                continue;
            }
            $id = $this->childText($child, 'ID');
            if (in_array($id, [self::ICV_ID, self::PIH_ID], true)) {
                $toRemove[] = $child;
            }
        }

        foreach ($toRemove as $node) {
            $root->removeChild($node);
        }
    }

    private function accountingSupplierParty(DOMElement $root): ?DOMElement
    {
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'AccountingSupplierParty') {
                return $child;
            }
        }

        return null;
    }

    private function createIcvReference(DOMDocument $document, Icv $icv): DOMElement
    {
        $reference = $document->createElementNS(UblNamespaces::CAC, 'cac:AdditionalDocumentReference');
        $id = $document->createElementNS(UblNamespaces::CBC, 'cbc:ID');
        $id->appendChild($document->createTextNode(self::ICV_ID));
        $uuid = $document->createElementNS(UblNamespaces::CBC, 'cbc:UUID');
        $uuid->appendChild($document->createTextNode($icv->toXmlDigits()));
        $reference->appendChild($id);
        $reference->appendChild($uuid);

        return $reference;
    }

    private function createPihReference(DOMDocument $document, Pih $pih): DOMElement
    {
        $reference = $document->createElementNS(UblNamespaces::CAC, 'cac:AdditionalDocumentReference');
        $id = $document->createElementNS(UblNamespaces::CBC, 'cbc:ID');
        $id->appendChild($document->createTextNode(self::PIH_ID));
        $attachment = $document->createElementNS(UblNamespaces::CAC, 'cac:Attachment');
        $embedded = $document->createElementNS(UblNamespaces::CBC, 'cbc:EmbeddedDocumentBinaryObject');
        $embedded->setAttribute('mimeCode', 'text/plain');
        $embedded->appendChild($document->createTextNode($pih->value()));
        $attachment->appendChild($embedded);
        $reference->appendChild($id);
        $reference->appendChild($attachment);

        return $reference;
    }

    private function childText(DOMElement $parent, string $localName): string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return trim($child->textContent);
            }
        }

        return '';
    }

    private function identity(GeneratedEInvoiceXml $generated): string
    {
        return $generated->sourceType.':'.$generated->sourceId.':snapshot:'.$generated->sourceSnapshotId;
    }
}
