<?php

namespace App\Services\EInvoicing;

use App\EInvoicing\EInvoiceParty;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceSupplier;

/**
 * Resolves seller/buyer party payloads for draft invoice readiness (live workspace data).
 */
final class FinanceInvoicePartyResolver
{
    public function sellerFromWorkspace(int $workspaceId): EInvoiceParty
    {
        $setting = FinanceSetting::forWorkspaceId($workspaceId);

        return EInvoiceParty::fromSnapshot($this->structuredAddress($this->companyArray($setting)));
    }

    public function partiesForDraftFinanceInvoice(FinanceInvoice $invoice): DraftInvoiceParties
    {
        $invoice->loadMissing(['customer', 'supplier']);

        $seller = EInvoiceParty::fromSnapshot(
            $this->structuredAddress($this->companyArray(FinanceSetting::forWorkspaceId((int) $invoice->workspace_id)))
        );

        $type = (string) $invoice->type;
        $buyerArray = $this->recipientArray(
            $type,
            $invoice->customer,
            $invoice->supplier,
            (string) ($invoice->customer_name ?? ''),
            $this->inferWalkIn($invoice),
        );

        $buyer = EInvoiceParty::fromSnapshot($this->structuredAddress($buyerArray));
        $isStandard = $this->isStandardSubtype((string) ($invoice->tax_document_subtype ?? 'standard'));

        return new DraftInvoiceParties($seller, $buyer, $isStandard);
    }

    public function isStandardSubtype(string $subtype): bool
    {
        return strtolower(trim($subtype)) !== 'simplified';
    }

    private function inferWalkIn(FinanceInvoice $invoice): bool
    {
        if ((string) $invoice->type !== 'sales') {
            return false;
        }

        if ($invoice->customer_id !== null) {
            return false;
        }

        $name = trim((string) ($invoice->customer_name ?? ''));

        return $name !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function companyArray(?FinanceSetting $setting): array
    {
        return [
            'kind' => 'company',
            'company_name' => $setting?->company_name,
            'company_name_ar' => $setting?->company_name_ar,
            'vat_number' => $setting?->vat_number,
            'commercial_registration' => $setting?->commercial_registration,
            'address_line' => $setting?->address_line,
            'building_number' => $setting?->building_number,
            'street' => $setting?->street,
            'district' => $setting?->district,
            'city' => $setting?->city,
            'postal_code' => $setting?->postal_code,
            'country_code' => $setting?->country_code,
            'phone' => $setting?->phone,
            'email' => $setting?->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recipientArray(
        string $type,
        ?Customer $customer,
        ?FinanceSupplier $supplier,
        string $cashCustomerName,
        bool $walkIn,
    ): array {
        if ($type === 'purchase') {
            return [
                'kind' => 'supplier',
                'walk_in' => false,
                'name' => $supplier?->name,
                'vat_number' => $supplier?->vat_number,
                'commercial_registration' => $supplier?->commercial_registration,
                'address' => $supplier?->address,
                'phone' => $supplier?->phone,
                'email' => $supplier?->email,
            ];
        }

        return [
            'kind' => 'customer',
            'walk_in' => $walkIn,
            'name' => $customer?->name ?: $cashCustomerName,
            'vat_number' => $customer?->vat_number,
            'commercial_registration' => $customer?->commercial_registration,
            'address' => $customer?->address,
            'building_number' => $customer?->building_number,
            'street' => $customer?->street,
            'district' => $customer?->district,
            'city' => $customer?->city,
            'postal_code' => $customer?->postal_code,
            'country_code' => $customer?->country_code,
            'additional_number' => $customer?->additional_number,
            'phone' => $customer?->phone,
            'email' => $customer?->email,
        ];
    }

    /**
     * @param  array<string, mixed>  $party
     * @return array<string, mixed>
     */
    private function structuredAddress(array $party): array
    {
        $existing = $party['address'] ?? null;
        $line = is_string($existing)
            ? $existing
            : ($party['address_line'] ?? (is_array($existing) ? ($existing['line'] ?? null) : null));

        $address = [
            'line' => $this->nullableString($line),
            'street' => $this->nullableString($party['street'] ?? (is_array($existing) ? ($existing['street'] ?? null) : null)),
            'building_number' => $this->nullableString($party['building_number'] ?? (is_array($existing) ? ($existing['building_number'] ?? null) : null)),
            'additional_number' => $this->nullableString($party['additional_number'] ?? (is_array($existing) ? ($existing['additional_number'] ?? null) : null)),
            'district' => $this->nullableString($party['district'] ?? (is_array($existing) ? ($existing['district'] ?? null) : null)),
            'city' => $this->nullableString($party['city'] ?? (is_array($existing) ? ($existing['city'] ?? null) : null)),
            'postal_code' => $this->nullableString($party['postal_code'] ?? (is_array($existing) ? ($existing['postal_code'] ?? null) : null)),
            'country_code' => $this->nullableString($party['country_code'] ?? (is_array($existing) ? ($existing['country_code'] ?? null) : null)),
        ];

        $party['address'] = $address;
        $party['address_line'] = $party['address_line'] ?? $address['line'];
        $party['street'] = $address['street'];
        $party['building_number'] = $address['building_number'];
        $party['additional_number'] = $address['additional_number'];
        $party['district'] = $address['district'];
        $party['city'] = $address['city'];
        $party['postal_code'] = $address['postal_code'];
        $party['country_code'] = $address['country_code'];

        return $party;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}

final readonly class DraftInvoiceParties
{
    public function __construct(
        public EInvoiceParty $seller,
        public EInvoiceParty $buyer,
        public bool $isStandard,
    ) {}
}
