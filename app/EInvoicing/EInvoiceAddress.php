<?php

namespace App\EInvoicing;

final readonly class EInvoiceAddress
{
    public function __construct(
        public ?string $line,
        public ?string $buildingNumber,
        public ?string $street,
        public ?string $district,
        public ?string $city,
        public ?string $postalCode,
        public ?string $countryCode,
        public ?string $additionalNumber,
    ) {}

    /**
     * @param  array<string, mixed>  $party
     */
    public static function fromSnapshotParty(array $party): self
    {
        $nested = is_array($party['address'] ?? null) ? $party['address'] : [];
        $lineFromString = is_string($party['address'] ?? null) ? $party['address'] : null;

        return new self(
            line: self::stringOrNull(
                $nested['line']
                    ?? $nested['address']
                    ?? $nested['address_line']
                    ?? $lineFromString
                    ?? $party['address_line']
                    ?? null
            ),
            buildingNumber: self::stringOrNull($nested['building_number'] ?? $party['building_number'] ?? null),
            street: self::stringOrNull($nested['street'] ?? $party['street'] ?? null),
            district: self::stringOrNull($nested['district'] ?? $party['district'] ?? null),
            city: self::stringOrNull($nested['city'] ?? $party['city'] ?? null),
            postalCode: self::stringOrNull($nested['postal_code'] ?? $party['postal_code'] ?? null),
            countryCode: self::stringOrNull($nested['country_code'] ?? $party['country_code'] ?? null),
            additionalNumber: self::stringOrNull($nested['additional_number'] ?? $party['additional_number'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'building_number' => $this->buildingNumber,
            'street' => $this->street,
            'district' => $this->district,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'additional_number' => $this->additionalNumber,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
