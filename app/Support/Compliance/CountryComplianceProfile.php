<?php

namespace App\Support\Compliance;

interface CountryComplianceProfile
{
    public function countryCode(): string;

    public function countryName(): string;

    public function defaultCurrency(): string;

    /**
     * Standard consumption-tax rate published by the tax authority.
     * Reduced/exempt schedules are NOT hardcoded here.
     */
    public function standardTaxRate(): string;

    public function standardTaxCode(): string;

    public function standardTaxName(): string;

    public function authorityName(): string;

    public function authorityUrl(): string;
}
