<?php

namespace App\EInvoicing\Xml;

use App\EInvoicing\EInvoiceAddress;
use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\EInvoiceLine;
use App\EInvoicing\EInvoiceParty;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Support\Money\Money;
use DOMElement;

/**
 * Maps an {@see EInvoiceDocument} into UBL 2.1 XML.
 *
 * Does not query live business tables or recalculate tax.
 */
final class UblMapper
{
    public function map(EInvoiceDocument $document): string
    {
        $this->assertCanMap($document);

        $builder = new XmlBuilder;
        $isCredit = $document->kind()->isCredit();
        $root = $builder->createRoot(
            $isCredit ? 'CreditNote' : 'Invoice',
            $isCredit ? UblNamespaces::CREDIT_NOTE : UblNamespaces::INVOICE,
        );

        $builder->cbc($root, 'UBLVersionID', UblNamespaces::UBL_VERSION);
        $builder->cbc($root, 'ProfileID', UblNamespaces::PROFILE_ID);
        $builder->cbc($root, 'ID', $document->documentNumber);
        $builder->cbc($root, 'UUID', DocumentUuid::fromDocument($document));
        $builder->cbc($root, 'IssueDate', $this->issueDate($document));

        $issueTime = $this->issueTime($document);
        if ($issueTime !== null) {
            $builder->cbc($root, 'IssueTime', $issueTime);
        }

        $typeCode = $builder->cbc(
            $root,
            $isCredit ? 'CreditNoteTypeCode' : 'InvoiceTypeCode',
            $document->typeCode()->value,
        );
        $builder->attr($typeCode, 'name', $document->transactionCode()->value);

        $note = $document->reason ?? $document->notes;
        if ($note !== null) {
            $builder->cbc($root, 'Note', $note);
        }

        $builder->cbc($root, 'DocumentCurrencyCode', $document->currency);

        if ($isCredit || $document->kind()->isDebit()) {
            $this->mapBillingReference($builder, $root, $document);
        }

        $this->mapParty($builder, $root, 'AccountingSupplierParty', $document->seller, $document, true);
        $this->mapParty($builder, $root, 'AccountingCustomerParty', $document->buyer, $document, false);

        $this->mapDelivery($builder, $root, $document);
        $this->mapPaymentMeans($builder, $root, $document);

        if (Money::cmp($document->totals->discount, '0') === 1) {
            $this->mapAllowance($builder, $root, $document->totals->discount, $document);
        }

        $this->mapTaxTotal($builder, $root, $document);
        $this->mapLegalMonetaryTotal($builder, $root, $document);
        $this->mapLines($builder, $root, $document, $isCredit);

        return $builder->toXml();
    }

    private function assertCanMap(EInvoiceDocument $document): void
    {
        $source = $this->sourceIdentity($document);

        if ($document->kind() === ElectronicDocumentKind::PurchaseInvoice
            || $document->kind() === ElectronicDocumentKind::PosCashierInvoice) {
            throw new EInvoiceXmlMappingException(
                'Document kind is not a sales e-invoice, credit note, or debit note.',
                $source,
                'invoice_type.kind',
            );
        }

        if ($document->typeCode() === null) {
            throw new EInvoiceXmlMappingException(
                'Invoice type code is missing. XML will not guess 388/381/383.',
                $source,
                'invoice_type.type_code',
            );
        }

        if ($document->transactionCode() === null) {
            throw new EInvoiceXmlMappingException(
                'Transaction code is missing. XML will not guess 0100000/0200000.',
                $source,
                'invoice_type.transaction_code',
            );
        }

        if ($document->documentNumber === '') {
            throw new EInvoiceXmlMappingException('Document number is required.', $source, 'document_number');
        }

        if ($this->issueDate($document) === '') {
            throw new EInvoiceXmlMappingException('Issue date is required.', $source, 'issue_date');
        }

        if ($document->kind()->isCredit() || $document->kind()->isDebit()) {
            if ($document->originalDocument?->invoiceNumber === null) {
                throw new EInvoiceXmlMappingException(
                    'Credit/debit notes require the original invoice number.',
                    $source,
                    'original_document.invoice_number',
                );
            }
        }

        if ($document->lines === []) {
            throw new EInvoiceXmlMappingException('At least one invoice line is required.', $source, 'lines');
        }

        UblTaxCategory::fromClassification($document->tax->classification);
        $this->assertExemptionRequirements($document);
    }

    private function assertExemptionRequirements(EInvoiceDocument $document): void
    {
        $category = UblTaxCategory::fromClassification($document->tax->classification);
        if (! in_array($category, [UblTaxCategory::Exempt, UblTaxCategory::OutOfScope], true)) {
            return;
        }

        $code = $this->exemptionCode($document);
        if ($code === null) {
            throw new EInvoiceXmlMappingException(
                'Exempt and out-of-scope documents require an official VATEX-SA exemption code from the snapshot.',
                $this->sourceIdentity($document),
                'tax.exemption_code',
            );
        }

        if (! UblTaxCategory::isOfficialExemptionCode($code)) {
            throw new EInvoiceXmlMappingException(
                'Exemption code is not in the official ZATCA §11.2.4 list; it will not be invented.',
                $this->sourceIdentity($document),
                'tax.exemption_code',
            );
        }
    }

    private function mapBillingReference(XmlBuilder $builder, DOMElement $root, EInvoiceDocument $document): void
    {
        $reference = $builder->cac($root, 'BillingReference');
        $invoiceRef = $builder->cac($reference, 'InvoiceDocumentReference');
        $builder->cbc($invoiceRef, 'ID', (string) $document->originalDocument?->invoiceNumber);

        $issueDate = $document->originalDocument?->invoiceIssueDate;
        if (is_string($issueDate) && $issueDate !== '') {
            $builder->cbc($invoiceRef, 'IssueDate', substr($issueDate, 0, 10));
        }
    }

    private function mapParty(
        XmlBuilder $builder,
        DOMElement $root,
        string $wrapper,
        EInvoiceParty $party,
        EInvoiceDocument $document,
        bool $seller,
    ): void {
        $source = $this->sourceIdentity($document);
        $path = $seller ? 'seller' : 'buyer';
        $standard = $document->isStandard();

        if ($party->name === null || $party->name === '') {
            if ($seller || $standard) {
                throw new EInvoiceXmlMappingException('Party name is required.', $source, $path.'.name');
            }
        }

        if ($seller) {
            if ($party->vatNumber === null || $party->vatNumber === '') {
                throw new EInvoiceXmlMappingException('Seller VAT number is required.', $source, 'seller.vat_number');
            }
            if ($party->commercialRegistration === null || $party->commercialRegistration === '') {
                throw new EInvoiceXmlMappingException(
                    'Seller commercial registration is required (BR-KSA-08).',
                    $source,
                    'seller.commercial_registration',
                );
            }
            $this->assertSellerAddress($party->address, $source);
        } elseif ($standard && ! $party->walkIn) {
            $this->assertBuyerAddress($party->address, $source);
        }

        $wrapperEl = $builder->cac($root, $wrapper);
        $partyEl = $builder->cac($wrapperEl, 'Party');

        if ($seller && $party->commercialRegistration !== null) {
            $id = $builder->cac($partyEl, 'PartyIdentification');
            $idValue = $builder->cbc($id, 'ID', $party->commercialRegistration);
            $builder->attr($idValue, 'schemeID', 'CRN');
        }

        $address = $builder->cac($partyEl, 'PostalAddress');
        $this->mapAddress($builder, $address, $party->address);

        if ($party->vatNumber !== null && $party->vatNumber !== '') {
            $tax = $builder->cac($partyEl, 'PartyTaxScheme');
            $builder->cbc($tax, 'CompanyID', $party->vatNumber);
            $scheme = $builder->cac($tax, 'TaxScheme');
            $builder->cbc($scheme, 'ID', UblNamespaces::TAX_SCHEME_VAT);
        }

        if ($party->name !== null && $party->name !== '') {
            $legal = $builder->cac($partyEl, 'PartyLegalEntity');
            $builder->cbc($legal, 'RegistrationName', $party->name);
        }
    }

    private function assertSellerAddress(EInvoiceAddress $address, string $source): void
    {
        $required = [
            'street' => $address->street ?? $address->line,
            'building_number' => $address->buildingNumber,
            'postal_code' => $address->postalCode,
            'city' => $address->city,
            'district' => $address->district,
            'country_code' => $address->countryCode,
        ];

        foreach ($required as $field => $value) {
            if ($value === null || $value === '') {
                throw new EInvoiceXmlMappingException(
                    "Seller address {$field} is required (BR-KSA-09) and is missing from the snapshot.",
                    $source,
                    'seller.address.'.$field,
                );
            }
        }
    }

    private function assertBuyerAddress(EInvoiceAddress $address, string $source): void
    {
        $required = [
            'street' => $address->street ?? $address->line,
            'city' => $address->city,
            'country_code' => $address->countryCode,
        ];

        foreach ($required as $field => $value) {
            if ($value === null || $value === '') {
                throw new EInvoiceXmlMappingException(
                    "Buyer address {$field} is required for standard invoices (BR-KSA-10).",
                    $source,
                    'buyer.address.'.$field,
                );
            }
        }
    }

    private function mapAddress(
        XmlBuilder $builder,
        DOMElement $address,
        EInvoiceAddress $value,
    ): void {
        $street = $value->street ?? $value->line;
        if ($street !== null) {
            $builder->cbc($address, 'StreetName', $street);
        }
        if ($value->additionalNumber !== null) {
            $builder->cbc($address, 'AdditionalStreetName', $value->additionalNumber);
        }
        if ($value->buildingNumber !== null) {
            $builder->cbc($address, 'BuildingNumber', $value->buildingNumber);
        }
        if ($value->district !== null) {
            $builder->cbc($address, 'CitySubdivisionName', $value->district);
        }
        if ($value->city !== null) {
            $builder->cbc($address, 'CityName', $value->city);
        }
        if ($value->postalCode !== null) {
            $builder->cbc($address, 'PostalZone', $value->postalCode);
        }
        $countryCode = $value->countryCode;
        if ($countryCode !== null) {
            $country = $builder->cac($address, 'Country');
            $builder->cbc($country, 'IdentificationCode', $countryCode);
        }
    }

    private function mapDelivery(XmlBuilder $builder, DOMElement $root, EInvoiceDocument $document): void
    {
        if ($document->supplyDate === null || $document->supplyDate === '') {
            return;
        }

        $delivery = $builder->cac($root, 'Delivery');
        $builder->cbc($delivery, 'ActualDeliveryDate', substr($document->supplyDate, 0, 10));
    }

    private function mapPaymentMeans(XmlBuilder $builder, DOMElement $root, EInvoiceDocument $document): void
    {
        $code = $document->payment['regulatory_code'] ?? null;
        if (! is_string($code) || trim($code) === '') {
            return;
        }

        $means = $builder->cac($root, 'PaymentMeans');
        $builder->cbc($means, 'PaymentMeansCode', trim($code));
    }

    private function mapAllowance(XmlBuilder $builder, DOMElement $parent, string $amount, EInvoiceDocument $document): void
    {
        $allowance = $builder->cac($parent, 'AllowanceCharge');
        $builder->cbc($allowance, 'ChargeIndicator', 'false');
        $builder->money($allowance, 'Amount', $amount, $document->currency);
        $category = $builder->cac($allowance, 'TaxCategory');
        $builder->cbc($category, 'ID', UblTaxCategory::fromClassification($document->tax->classification)->value);
        $builder->cbc($category, 'Percent', $document->tax->rate);
        $scheme = $builder->cac($category, 'TaxScheme');
        $builder->cbc($scheme, 'ID', UblNamespaces::TAX_SCHEME_VAT);
    }

    private function mapTaxTotal(XmlBuilder $builder, DOMElement $root, EInvoiceDocument $document): void
    {
        $taxTotal = $builder->cac($root, 'TaxTotal');
        $builder->money($taxTotal, 'TaxAmount', $document->tax->amount, $document->currency);

        $subtotal = $builder->cac($taxTotal, 'TaxSubtotal');
        $builder->money($subtotal, 'TaxableAmount', $document->totals->taxableAmount, $document->currency);
        $builder->money($subtotal, 'TaxAmount', $document->tax->amount, $document->currency);

        $category = $builder->cac($subtotal, 'TaxCategory');
        $mapped = UblTaxCategory::fromClassification($document->tax->classification);
        $builder->cbc($category, 'ID', $mapped->value);
        $builder->cbc($category, 'Percent', $document->tax->rate);

        $exemptionCode = $this->exemptionCode($document);
        if ($exemptionCode !== null && UblTaxCategory::isOfficialExemptionCode($exemptionCode)) {
            $builder->cbc($category, 'TaxExemptionReasonCode', $exemptionCode);
        }
        $exemptionReason = $this->exemptionReason($document);
        if ($exemptionReason !== null) {
            $builder->cbc($category, 'TaxExemptionReason', $exemptionReason);
        }

        $scheme = $builder->cac($category, 'TaxScheme');
        $builder->cbc($scheme, 'ID', UblNamespaces::TAX_SCHEME_VAT);
    }

    private function mapLegalMonetaryTotal(XmlBuilder $builder, DOMElement $root, EInvoiceDocument $document): void
    {
        $totals = $builder->cac($root, 'LegalMonetaryTotal');
        $currency = $document->currency;
        $builder->money($totals, 'LineExtensionAmount', $document->totals->subtotal, $currency);
        if (Money::cmp($document->totals->discount, '0') === 1) {
            $builder->money($totals, 'AllowanceTotalAmount', $document->totals->discount, $currency);
        }
        $builder->money($totals, 'TaxExclusiveAmount', $document->totals->taxableAmount, $currency);
        $builder->money($totals, 'TaxInclusiveAmount', $document->totals->total, $currency);
        $payable = $document->totals->amountDue ?? $document->totals->total;
        $builder->money($totals, 'PayableAmount', $payable, $currency);
    }

    private function mapLines(XmlBuilder $builder, DOMElement $root, EInvoiceDocument $document, bool $isCredit): void
    {
        foreach (array_values($document->lines) as $index => $line) {
            $this->mapLine($builder, $root, $document, $line, $index + 1, $isCredit);
        }
    }

    private function mapLine(
        XmlBuilder $builder,
        DOMElement $root,
        EInvoiceDocument $document,
        EInvoiceLine $line,
        int $id,
        bool $isCredit,
    ): void {
        $lineEl = $builder->cac($root, $isCredit ? 'CreditNoteLine' : 'InvoiceLine');
        $builder->cbc($lineEl, 'ID', (string) $id);
        $quantity = $builder->cbc($lineEl, $isCredit ? 'CreditedQuantity' : 'InvoicedQuantity', $line->quantity);
        if ($line->unitCode !== null && $line->unitCode !== '') {
            $builder->attr($quantity, 'unitCode', $line->unitCode);
        }

        $net = $line->taxableAmount ?? $line->total;
        $builder->money($lineEl, 'LineExtensionAmount', $net, $document->currency);

        if (Money::cmp($line->discount, '0') === 1) {
            $allowance = $builder->cac($lineEl, 'AllowanceCharge');
            $builder->cbc($allowance, 'ChargeIndicator', 'false');
            $builder->money($allowance, 'Amount', $line->discount, $document->currency);
        }

        $item = $builder->cac($lineEl, 'Item');
        $name = $line->productName ?? $line->description;
        if ($name === null || $name === '') {
            throw new EInvoiceXmlMappingException(
                'Line item name is required.',
                $this->sourceIdentity($document),
                'lines.'.$id.'.product_name',
            );
        }
        if ($line->description !== null && $line->description !== $name) {
            $builder->cbc($item, 'Description', $line->description);
        }
        $builder->cbc($item, 'Name', $name);

        $classified = $builder->cac($item, 'ClassifiedTaxCategory');
        $category = UblTaxCategory::fromClassification($line->taxClassification);
        $builder->cbc($classified, 'ID', $category->value);
        if ($line->taxRate !== null) {
            $builder->cbc($classified, 'Percent', $line->taxRate);
        }
        $scheme = $builder->cac($classified, 'TaxScheme');
        $builder->cbc($scheme, 'ID', UblNamespaces::TAX_SCHEME_VAT);

        $price = $builder->cac($lineEl, 'Price');
        $builder->money($price, 'PriceAmount', $line->unitPrice, $document->currency);
    }

    private function issueDate(EInvoiceDocument $document): string
    {
        if ($document->issueDate === null || $document->issueDate === '') {
            return '';
        }

        return substr($document->issueDate, 0, 10);
    }

    private function issueTime(EInvoiceDocument $document): ?string
    {
        if ($document->issuedAt === null || $document->issuedAt === '') {
            return null;
        }

        if (preg_match('/T(\d{2}:\d{2}:\d{2})/', $document->issuedAt, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\s(\d{2}:\d{2}:\d{2})/', $document->issuedAt, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function exemptionCode(EInvoiceDocument $document): ?string
    {
        foreach ($document->lines as $line) {
            if (is_string($line->exemptionCode) && $line->exemptionCode !== '') {
                return $line->exemptionCode;
            }
        }

        return null;
    }

    private function exemptionReason(EInvoiceDocument $document): ?string
    {
        foreach ($document->lines as $line) {
            if (is_string($line->exemptionReason) && $line->exemptionReason !== '') {
                return $line->exemptionReason;
            }
        }

        return null;
    }

    private function sourceIdentity(EInvoiceDocument $document): string
    {
        return sprintf(
            '%s:%d@snapshot:%d',
            $document->sourceType,
            $document->sourceId,
            $document->sourceSnapshotId,
        );
    }
}
