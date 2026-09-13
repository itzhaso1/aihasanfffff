<?php

namespace App\Services\Finance\Api\Dto;

final readonly class InvoicePartyDto
{
    public function __construct(
        public ?string $kind,
        public ?string $name,
        public ?string $vatNumber,
        public ?string $commercialRegistration,
        public ?string $phone,
        public ?string $email,
        public bool $walkIn,
        public ?string $addressLine,
        public ?string $city,
        public ?string $countryCode,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'name' => $this->name,
            'vat_number' => $this->vatNumber,
            'commercial_registration' => $this->commercialRegistration,
            'phone' => $this->phone,
            'email' => $this->email,
            'walk_in' => $this->walkIn,
            'address' => [
                'line' => $this->addressLine,
                'city' => $this->city,
                'country_code' => $this->countryCode,
            ],
        ];
    }
}
