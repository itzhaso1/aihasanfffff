<?php

namespace App\Support\Compliance;

/**
 * Jordan GST defaults verified from ISTD FAQ (Article 6/a): standard rate 16%.
 * Source: https://istd.gov.jo/EN/Modules/faq — last checked 2026-02-25.
 * Reduced/exempt schedules are maintained by ISTD and must be configured per workspace.
 */
class JordanCompliance implements CountryComplianceProfile
{
    public function countryCode(): string
    {
        return 'JO';
    }

    public function countryName(): string
    {
        return 'Jordan';
    }

    public function defaultCurrency(): string
    {
        return 'JOD';
    }

    public function standardTaxRate(): string
    {
        return '16.00';
    }

    public function standardTaxCode(): string
    {
        return 'GST_JO_STD_16';
    }

    public function standardTaxName(): string
    {
        return 'Jordan GST Standard';
    }

    public function authorityName(): string
    {
        return 'ISTD';
    }

    public function authorityUrl(): string
    {
        return 'https://istd.gov.jo';
    }
}
