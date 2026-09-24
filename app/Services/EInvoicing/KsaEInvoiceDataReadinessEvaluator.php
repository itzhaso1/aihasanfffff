<?php

namespace App\Services\EInvoicing;

use App\EInvoicing\EInvoiceAddress;
use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\EInvoiceParty;

/**
 * KSA seller/buyer data gates aligned with {@see \App\EInvoicing\Xml\UblMapper} party rules.
 *
 * Does not modify UBL output; mirrors current mapper requirements only.
 */
final class KsaEInvoiceDataReadinessEvaluator
{
    public function evaluateDocument(EInvoiceDocument $document): KsaDataReadinessPair
    {
        $standard = $document->isStandard();

        return new KsaDataReadinessPair(
            seller: $this->evaluateSeller($document->seller),
            buyer: $this->evaluateBuyer($document->buyer, $standard),
        );
    }

    public function evaluateSeller(EInvoiceParty $party): PartyDataReadiness
    {
        $fields = [];
        $errors = [];

        $this->requireField($errors, $fields, 'seller.name', 'seller.name', $party->name, 'seller_name_required', 'Seller legal name is required for e-invoice XML.');
        $this->requireField($errors, $fields, 'seller.vat_number', 'seller.vat_number', $party->vatNumber, 'seller_vat_required', 'Seller VAT number is required for e-invoice XML.');
        $this->requireField(
            $errors,
            $fields,
            'seller.commercial_registration',
            'seller.commercial_registration',
            $party->commercialRegistration,
            'seller_crn_required',
            'Seller commercial registration is required by the current XML mapper (BR-KSA-08 label in code).',
        );

        $this->evaluateSellerAddress($party->address, $errors, $fields);

        return new PartyDataReadiness(
            ready: $errors === [],
            errors: $errors,
            fields: $fields,
        );
    }

    public function evaluateBuyer(EInvoiceParty $party, bool $standard): PartyDataReadiness
    {
        $fields = [];
        $errors = [];
        $warnings = [];

        if ($standard) {
            $this->requireField($errors, $fields, 'buyer.name', 'buyer.name', $party->name, 'buyer_name_required', 'Buyer name is required for standard invoices.');
        } else {
            $fields['buyer.name'] = filled($party->name) ? 'ok' : 'optional';
        }

        if ($standard && ! $party->walkIn) {
            $this->evaluateBuyerAddress($party->address, $errors, $fields);
        } else {
            $fields['buyer.address'] = $party->walkIn ? 'walk_in_relaxed' : 'not_required';
        }

        if ($standard && ! $party->walkIn && blank($party->vatNumber)) {
            $warnings[] = new ComplianceIssue(
                code: 'buyer_vat_unverified',
                field: 'buyer.vat_number',
                message: 'Buyer VAT is not enforced by the current XML mapper; official B2B rules may require verification in a later phase.',
            );
            $fields['buyer.vat_number'] = 'warning';
        }

        return new PartyDataReadiness(
            ready: $errors === [],
            errors: $errors,
            warnings: $warnings,
            fields: $fields,
        );
    }

    public function evaluateWorkspaceSeller(EInvoiceParty $party): PartyDataReadiness
    {
        return $this->evaluateSeller($party);
    }

    /**
     * @param  list<ComplianceIssue>  $errors
     * @param  array<string, string>  $fields
     */
    private function evaluateSellerAddress(EInvoiceAddress $address, array &$errors, array &$fields): void
    {
        $required = [
            'seller.address.street' => $address->street ?? $address->line,
            'seller.address.building_number' => $address->buildingNumber,
            'seller.address.postal_code' => $address->postalCode,
            'seller.address.city' => $address->city,
            'seller.address.district' => $address->district,
            'seller.address.country_code' => $address->countryCode,
        ];

        foreach ($required as $field => $value) {
            $short = str_replace('seller.address.', '', $field);
            if ($value === null || $value === '') {
                $errors[] = new ComplianceIssue(
                    code: 'seller_address_incomplete',
                    field: $field,
                    message: "Seller address {$short} is required by the current XML mapper (BR-KSA-09 label in code).",
                );
                $fields[$field] = 'missing';
            } else {
                $fields[$field] = 'ok';
            }
        }
    }

    /**
     * @param  list<ComplianceIssue>  $errors
     * @param  array<string, string>  $fields
     */
    private function evaluateBuyerAddress(EInvoiceAddress $address, array &$errors, array &$fields): void
    {
        $required = [
            'buyer.address.street' => $address->street ?? $address->line,
            'buyer.address.city' => $address->city,
            'buyer.address.country_code' => $address->countryCode,
        ];

        foreach ($required as $field => $value) {
            $short = str_replace('buyer.address.', '', $field);
            if ($value === null || $value === '') {
                $errors[] = new ComplianceIssue(
                    code: 'buyer_address_incomplete',
                    field: $field,
                    message: "Buyer address {$short} is required for standard invoices by the current XML mapper (BR-KSA-10 label in code).",
                );
                $fields[$field] = 'missing';
            } else {
                $fields[$field] = 'ok';
            }
        }
    }

    /**
     * @param  list<ComplianceIssue>  $errors
     * @param  array<string, string>  $fields
     */
    private function requireField(
        array &$errors,
        array &$fields,
        string $fieldKey,
        string $fieldPath,
        ?string $value,
        string $code,
        string $message,
    ): void {
        if ($value === null || trim($value) === '') {
            $errors[] = new ComplianceIssue($code, $fieldPath, $message);
            $fields[$fieldKey] = 'missing';
        } else {
            $fields[$fieldKey] = 'ok';
        }
    }
}

final readonly class KsaDataReadinessPair
{
    public function __construct(
        public PartyDataReadiness $seller,
        public PartyDataReadiness $buyer,
    ) {}

    /**
     * @return list<ComplianceIssue>
     */
    public function allIssues(): array
    {
        return [...$this->seller->errors, ...$this->buyer->errors];
    }

    /**
     * @return list<ComplianceIssue>
     */
    public function allWarnings(): array
    {
        return [...$this->seller->warnings, ...$this->buyer->warnings];
    }
}
