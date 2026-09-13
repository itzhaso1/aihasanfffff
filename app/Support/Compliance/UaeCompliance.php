<?php

namespace App\Support\Compliance;

/**
 * UAE Federal VAT standard rate is 5% (Federal Tax Authority).
 * Special/zero/exempt treatments must be configured per workspace, not hardcoded.
 */
class UaeCompliance implements CountryComplianceProfile
{
    public function countryCode(): string
    {
        return 'AE';
    }

    public function countryName(): string
    {
        return 'United Arab Emirates';
    }

    public function defaultCurrency(): string
    {
        return 'AED';
    }

    public function standardTaxRate(): string
    {
        return '5.00';
    }

    public function standardTaxCode(): string
    {
        return 'VAT_AE_STD_5';
    }

    public function standardTaxName(): string
    {
        return 'UAE VAT Standard';
    }

    public function authorityName(): string
    {
        return 'Federal Tax Authority';
    }

    public function authorityUrl(): string
    {
        return 'https://tax.gov.ae';
    }
}
