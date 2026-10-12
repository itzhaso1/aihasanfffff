<?php

namespace App\Support\Compliance;

class ComplianceManager
{
    /**
     * @var array<string, class-string<CountryComplianceProfile>>
     */
    private array $profiles = [
        'SA' => SaudiArabiaCompliance::class,
        'JO' => JordanCompliance::class,
        'AE' => UaeCompliance::class,
    ];

    public function profile(?string $countryCode): CountryComplianceProfile
    {
        $code = strtoupper(trim((string) $countryCode));
        if ($code === '' || ! isset($this->profiles[$code])) {
            return new SaudiArabiaCompliance;
        }

        return new $this->profiles[$code];
    }

    /**
     * @return array<int, CountryComplianceProfile>
     */
    public function all(): array
    {
        return array_map(fn (string $class) => new $class, array_values($this->profiles));
    }
}
