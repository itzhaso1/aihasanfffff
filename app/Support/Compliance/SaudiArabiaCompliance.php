<?php

namespace App\Support\Compliance;

class SaudiArabiaCompliance implements CountryComplianceProfile
{
    public function countryCode(): string
    {
        return 'SA';
    }

    public function countryName(): string
    {
        return 'Saudi Arabia';
    }

    public function defaultCurrency(): string
    {
        return 'SAR';
    }

    public function standardTaxRate(): string
    {
        return '15.00';
    }

    public function standardTaxCode(): string
    {
        return 'VAT_STD_15';
    }

    public function standardTaxName(): string
    {
        return 'Saudi VAT Standard';
    }

    public function authorityName(): string
    {
        return 'ZATCA';
    }

    public function authorityUrl(): string
    {
        return 'https://zatca.gov.sa';
    }
}
