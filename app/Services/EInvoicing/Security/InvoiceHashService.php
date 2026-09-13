<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\CanonicalizationMethod;
use App\EInvoicing\Security\CanonicalXml;
use App\EInvoicing\Security\Exceptions\InvoiceHashException;
use App\EInvoicing\Security\HashAlgorithm;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Xml\UblNamespaces;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Computes the invoice hash from generated (optionally ICV/PIH-enriched) XML.
 *
 * Official BR-KSA-26 / Detailed Technical Guideline steps:
 * 1. Remove UBLExtensions
 * 2. Remove AdditionalDocumentReference where ID = QR
 * 3. Remove Signature
 * 4. Canonicalize (C14N 1.1; see CanonicalizationMethod)
 * 5. SHA-256 of the canonical octets (binary)
 * 6. Base64-encode the binary digest
 *
 * ICV and PIH remain in the hashed XML. QR, signature, and UBLExtensions do not.
 * This service does not query business tables or rebuild XML from invoices.
 */
final class InvoiceHashService
{
    public function hash(string $xml, ?string $documentIdentity = null): InvoiceHash
    {
        $canonical = $this->canonicalize($xml, $documentIdentity);

        return InvoiceHash::fromBinary(HashAlgorithm::sha256Binary($canonical->value()));
    }

    public function canonicalize(string $xml, ?string $documentIdentity = null): CanonicalXml
    {
        $document = $this->parse($xml, $documentIdentity);
        $this->assertNoXmlCoreAttributes($document, $documentIdentity);
        $this->excludeNonHashNodes($document);

        $canonical = $document->C14N(false, false);
        if ($canonical === false || $canonical === '') {
            throw new InvoiceHashException(
                'Canonicalization produced an empty document.',
                documentIdentity: $documentIdentity,
                operation: 'canonicalize',
                reason: 'empty_c14n',
            );
        }

        return new CanonicalXml($canonical);
    }

    public function sourceDigest(string $xml): string
    {
        return HashAlgorithm::sha256Base64($xml);
    }

    public function algorithm(): string
    {
        return HashAlgorithm::name();
    }

    public function canonicalizationMethod(): string
    {
        return CanonicalizationMethod::name();
    }

    private function parse(string $xml, ?string $documentIdentity): DOMDocument
    {
        if (trim($xml) === '') {
            throw new InvoiceHashException(
                'Security XML input is empty.',
                documentIdentity: $documentIdentity,
                operation: 'parse',
                reason: 'empty_xml',
            );
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement === null) {
            throw new InvoiceHashException(
                'Security XML input is not well-formed.',
                documentIdentity: $documentIdentity,
                operation: 'parse',
                reason: 'malformed_xml',
            );
        }

        return $document;
    }

    private function excludeNonHashNodes(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cac', UblNamespaces::CAC);
        $xpath->registerNamespace('cbc', UblNamespaces::CBC);

        $this->removeNodes($document->getElementsByTagName('UBLExtensions'));
        $this->removeNodes($document->getElementsByTagName('Signature'));

        $qrReferences = $xpath->query('//cac:AdditionalDocumentReference[normalize-space(cbc:ID)="QR"]');
        if ($qrReferences !== false) {
            $this->removeNodeList($qrReferences);
        }
    }

    /**
     * @param  \DOMNodeList<DOMNode>  $nodes
     */
    private function removeNodes(\DOMNodeList $nodes): void
    {
        $this->removeNodeList($nodes);
    }

    /**
     * @param  \DOMNodeList<int, DOMNode>|iterable<DOMNode>  $nodes
     */
    private function removeNodeList(iterable $nodes): void
    {
        $snapshot = [];
        foreach ($nodes as $node) {
            $snapshot[] = $node;
        }

        foreach ($snapshot as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function assertNoXmlCoreAttributes(DOMDocument $document, ?string $documentIdentity): void
    {
        $root = $document->documentElement;
        if ($root === null) {
            return;
        }

        $this->walkElements($root, function (DOMElement $element) use ($documentIdentity): void {
            if (! $element->hasAttributes()) {
                return;
            }

            foreach ($element->attributes as $attribute) {
                $name = $attribute->nodeName;
                if (in_array($name, ['xml:id', 'xml:base', 'xml:lang', 'xml:space'], true)
                    || $attribute->namespaceURI === 'http://www.w3.org/XML/1998/namespace') {
                    throw new InvoiceHashException(
                        'XML contains xml:* attributes. PHP C14N 1.0 is not equivalent to the official C14N 1.1 requirement for those attributes; hashing is refused rather than guessed.',
                        documentIdentity: $documentIdentity,
                        operation: 'canonicalize',
                        reason: 'xml_core_attributes_unsupported',
                    );
                }
            }
        });
    }

    /**
     * @param  callable(DOMElement): void  $callback
     */
    private function walkElements(DOMElement $element, callable $callback): void
    {
        $callback($element);
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->walkElements($child, $callback);
            }
        }
    }
}
