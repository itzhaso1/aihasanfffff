<?php

namespace App\EInvoicing\Security;

/**
 * Canonicalization used before the invoice hash.
 *
 * Official requirement (XML Implementation Standard BR-KSA-26 / Detailed
 * Technical Guideline): Canonical XML 1.1 (C14N 1.1).
 *
 * PHP DOM implements inclusive C14N 1.0 (DOMNode::C14N). C14N 1.1 differs
 * for xml:id, xml:base, xml:lang, and xml:space. HASEM UBL output does not
 * emit those attributes; the hash service rejects them rather than guessing
 * a C14N 1.1 transform. For that input set, inclusive C14N 1.0 is equivalent.
 */
final class CanonicalizationMethod
{
    public const C14N11 = 'http://www.w3.org/2006/12/xml-c14n11';

    public const ENGINE = 'php-dom-c14n-1.0-inclusive';

    public static function name(): string
    {
        return self::C14N11;
    }
}
