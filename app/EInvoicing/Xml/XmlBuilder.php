<?php

namespace App\EInvoicing\Xml;

use DOMDocument;
use DOMElement;

/**
 * Deterministic UBL writer. Namespace URIs come only from {@see UblNamespaces}.
 */
final class XmlBuilder
{
    private readonly DOMDocument $document;

    public function __construct()
    {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;
        $this->document->preserveWhiteSpace = false;
    }

    public function createRoot(string $localName, string $namespace): DOMElement
    {
        $root = $this->document->createElementNS($namespace, $localName);
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:'.UblNamespaces::PREFIX_CAC,
            UblNamespaces::CAC
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:'.UblNamespaces::PREFIX_CBC,
            UblNamespaces::CBC
        );
        $this->document->appendChild($root);

        return $root;
    }

    public function cbc(DOMElement $parent, string $localName, string $value): DOMElement
    {
        $element = $this->document->createElementNS(UblNamespaces::CBC, UblNamespaces::PREFIX_CBC.':'.$localName);
        $element->appendChild($this->document->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }

    public function cac(DOMElement $parent, string $localName): DOMElement
    {
        $element = $this->document->createElementNS(UblNamespaces::CAC, UblNamespaces::PREFIX_CAC.':'.$localName);
        $parent->appendChild($element);

        return $element;
    }

    public function attr(DOMElement $element, string $name, string $value): void
    {
        $element->setAttribute($name, $value);
    }

    public function money(DOMElement $parent, string $localName, string $amount, string $currency): DOMElement
    {
        $element = $this->cbc($parent, $localName, $amount);
        $this->attr($element, 'currencyID', $currency);

        return $element;
    }

    public function toXml(): string
    {
        $xml = $this->document->saveXML();
        if ($xml === false) {
            throw new EInvoiceXmlMappingException('Failed to serialize UBL XML.');
        }

        return $xml;
    }

    public function document(): DOMDocument
    {
        return $this->document;
    }
}
