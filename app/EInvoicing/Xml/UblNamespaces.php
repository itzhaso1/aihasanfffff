<?php

namespace App\EInvoicing\Xml;

/**
 * Official UBL 2.1 namespaces cited by ZATCA XML Implementation Standard ch. 10
 * and OASIS UBL 2.1 (os-UBL-2.1, 4 November 2013).
 *
 * Mappers must not invent additional namespace URIs.
 */
final class UblNamespaces
{
    public const INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public const CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    public const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public const PREFIX_CAC = 'cac';

    public const PREFIX_CBC = 'cbc';

    public const PROFILE_ID = 'reporting:1.0';

    public const UBL_VERSION = '2.1';

    public const TAX_SCHEME_VAT = 'VAT';
}
