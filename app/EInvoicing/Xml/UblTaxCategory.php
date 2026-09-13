<?php

namespace App\EInvoicing\Xml;

use App\Enums\EInvoicing\ElectronicTaxClassification;

/**
 * Verified UN/CEFACT 5305 subset from ZATCA XML Implementation Standard §11.2.4
 * and BR-KSA-18 (S, Z, E, O only).
 */
enum UblTaxCategory: string
{
    case Standard = 'S';
    case ZeroRated = 'Z';
    case Exempt = 'E';
    case OutOfScope = 'O';

    public static function fromClassification(ElectronicTaxClassification $classification): self
    {
        return match ($classification) {
            ElectronicTaxClassification::Standard => self::Standard,
            ElectronicTaxClassification::ZeroRated => self::ZeroRated,
            ElectronicTaxClassification::Exempt => self::Exempt,
            ElectronicTaxClassification::OutOfScope => self::OutOfScope,
            ElectronicTaxClassification::Unspecified => throw new EInvoiceXmlMappingException(
                'Tax classification is unspecified; XML cannot assign a UNCL5305 category.',
                fieldPath: 'tax.classification',
            ),
        };
    }

    /**
     * Official ZATCA exemption/exception reason codes from §11.2.4.
     *
     * @return list<string>
     */
    public static function officialExemptionCodes(): array
    {
        return [
            'VATEX-SA-29',
            'VATEX-SA-29-7',
            'VATEX-SA-30',
            'VATEX-SA-32',
            'VATEX-SA-33',
            'VATEX-SA-34-1',
            'VATEX-SA-34-2',
            'VATEX-SA-34-3',
            'VATEX-SA-34-4',
            'VATEX-SA-34-5',
            'VATEX-SA-35',
            'VATEX-SA-36',
            'VATEX-SA-EDU',
            'VATEX-SA-HEA',
            'VATEX-SA-MLTRY',
            'VATEX-SA-OOS',
        ];
    }

    public static function isOfficialExemptionCode(?string $code): bool
    {
        return is_string($code) && in_array($code, self::officialExemptionCodes(), true);
    }
}
