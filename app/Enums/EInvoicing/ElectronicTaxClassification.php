<?php

namespace App\Enums\EInvoicing;

use App\Enums\Finance\TaxProfileType;

/**
 * Business tax classification consumed by the E-Invoice domain.
 * Future regulatory category codes are intentionally not assigned here.
 */
enum ElectronicTaxClassification: string
{
    case Standard = 'standard';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';
    case OutOfScope = 'out_of_scope';
    case Unspecified = 'unspecified';

    public static function fromSnapshotValue(mixed $value): self
    {
        if (! is_string($value) || $value === '') {
            return self::Unspecified;
        }

        $profile = TaxProfileType::tryFrom($value);

        return $profile instanceof TaxProfileType
            ? self::from($profile->value)
            : self::Unspecified;
    }
}
