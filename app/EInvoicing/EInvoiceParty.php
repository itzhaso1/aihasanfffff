<?php

namespace App\EInvoicing;

final readonly class EInvoiceParty
{
    public function __construct(
        public ?string $kind,
        public ?string $name,
        public ?string $vatNumber,
        public ?string $commercialRegistration,
        public EInvoiceAddress $address,
        public ?string $phone,
        public ?string $email,
        public bool $walkIn = false,
    ) {}

    /**
     * @param  array<string, mixed>  $party
     */
    public static function fromSnapshot(array $party): self
    {
        return new self(
            kind: isset($party['kind']) ? (string) $party['kind'] : null,
            name: isset($party['name'])
                ? (string) $party['name']
                : (isset($party['company_name']) ? (string) $party['company_name'] : null),
            vatNumber: isset($party['vat_number']) ? (string) $party['vat_number'] : null,
            commercialRegistration: isset($party['commercial_registration'])
                ? (string) $party['commercial_registration']
                : (isset($party['cr_number']) ? (string) $party['cr_number'] : null),
            address: EInvoiceAddress::fromSnapshotParty($party),
            phone: isset($party['phone']) ? (string) $party['phone'] : null,
            email: isset($party['email']) ? (string) $party['email'] : null,
            walkIn: (bool) ($party['walk_in'] ?? false),
        );
    }

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
            'address' => $this->address->toArray(),
            'phone' => $this->phone,
            'email' => $this->email,
            'walk_in' => $this->walkIn,
        ];
    }
}
