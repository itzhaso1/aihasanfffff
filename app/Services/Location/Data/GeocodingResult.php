<?php

declare(strict_types=1);

namespace App\Services\Location\Data;

final class GeocodingResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $provider,
        public readonly ?string $countryCode = null,
        public readonly ?string $country = null,
        public readonly ?string $city = null,
        public readonly ?string $district = null,
        public readonly ?string $street = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $placeName = null,
        public readonly ?string $formattedAddress = null,
    ) {}

    public static function skipped(string $provider = 'none'): self
    {
        return new self(status: 'skipped', provider: $provider);
    }

    public static function failed(string $provider = 'nominatim'): self
    {
        return new self(status: 'failed', provider: $provider);
    }

    public function resolved(): bool
    {
        return $this->status === 'resolved';
    }
}
