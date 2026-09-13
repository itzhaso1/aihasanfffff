<?php

namespace Tests\Unit\EInvoicing;

use App\EInvoicing\InvoiceType;
use App\EInvoicing\InvoiceTypeCatalog;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\InvoiceTransactionCode;
use App\Enums\EInvoicing\InvoiceTypeCode;
use App\Models\Finance\IssuedDocumentSnapshot;
use Tests\TestCase;

class InvoiceTypeCatalogTest extends TestCase
{
    public function test_architecture_catalog_is_centralized(): void
    {
        $definitions = InvoiceTypeCatalog::definitions();

        $this->assertSame('388', $definitions['tax_invoice']['type_code']);
        $this->assertSame('0100000', $definitions['tax_invoice']['transaction_code']);
        $this->assertSame('388', $definitions['simplified_tax_invoice']['type_code']);
        $this->assertSame('0200000', $definitions['simplified_tax_invoice']['transaction_code']);
        $this->assertSame('381', $definitions['credit_note']['type_code']);
        $this->assertSame('0100000', $definitions['credit_note']['transaction_code']);
        $this->assertSame('381', $definitions['simplified_credit_note']['type_code']);
        $this->assertSame('0200000', $definitions['simplified_credit_note']['transaction_code']);
        $this->assertSame('383', $definitions['debit_note']['type_code']);
        $this->assertSame('0100000', $definitions['debit_note']['transaction_code']);
        $this->assertSame('383', $definitions['simplified_debit_note']['type_code']);
        $this->assertSame('0200000', $definitions['simplified_debit_note']['transaction_code']);
        $this->assertNull($definitions['pos_cashier_invoice']['type_code']);
        $this->assertNull($definitions['pos_cashier_invoice']['transaction_code']);
    }

    public function test_standard_sales_invoice_maps_to_tax_invoice(): void
    {
        $type = InvoiceTypeCatalog::fromSnapshot($this->snapshot(
            IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
            ['type' => 'sales', 'tax_document_subtype' => 'standard'],
        ));

        $this->assertSame(ElectronicDocumentKind::TaxInvoice, $type->kind);
        $this->assertSame(InvoiceTypeCode::TaxInvoice, $type->typeCode);
        $this->assertSame(InvoiceTransactionCode::Standard, $type->transactionCode);
        $this->assertTrue($type->isStandard());
        $this->assertFalse($type->isSimplified());
    }

    public function test_simplified_sales_invoice_maps_to_simplified_tax_invoice(): void
    {
        $type = InvoiceTypeCatalog::fromSnapshot($this->snapshot(
            IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
            ['type' => 'sales', 'tax_document_subtype' => 'simplified'],
        ));

        $this->assertSame(ElectronicDocumentKind::SimplifiedTaxInvoice, $type->kind);
        $this->assertSame(InvoiceTypeCode::TaxInvoice, $type->typeCode);
        $this->assertSame(InvoiceTransactionCode::Simplified, $type->transactionCode);
        $this->assertTrue($type->isSimplified());
        $this->assertFalse($type->isStandard());
    }

    public function test_credit_note_without_subtype_does_not_guess_transaction_code(): void
    {
        $type = InvoiceTypeCatalog::fromSnapshot($this->snapshot(
            IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE,
            ['type' => 'credit'],
        ));

        $this->assertSame(ElectronicDocumentKind::CreditNote, $type->kind);
        $this->assertSame(InvoiceTypeCode::CreditNote, $type->typeCode);
        $this->assertNull($type->transactionCode);
        $this->assertFalse($type->isStandard());
        $this->assertFalse($type->isSimplified());
    }

    public function test_debit_note_without_subtype_does_not_guess_transaction_code(): void
    {
        $type = InvoiceTypeCatalog::fromSnapshot($this->snapshot(
            IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE,
            ['type' => 'debit'],
        ));

        $this->assertSame(ElectronicDocumentKind::DebitNote, $type->kind);
        $this->assertSame(InvoiceTypeCode::DebitNote, $type->typeCode);
        $this->assertNull($type->transactionCode);
    }

    public function test_credit_note_uses_subtype_only_when_snapshot_has_it(): void
    {
        $simplified = InvoiceTypeCatalog::fromSnapshot($this->snapshot(
            IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE,
            ['type' => 'credit', 'tax_document_subtype' => 'simplified'],
        ));

        $this->assertSame(ElectronicDocumentKind::SimplifiedCreditNote, $simplified->kind);
        $this->assertSame(InvoiceTransactionCode::Simplified, $simplified->transactionCode);
        $this->assertTrue($simplified->isSimplified());
    }

    public function test_pos_invoice_does_not_guess_regulatory_codes(): void
    {
        $type = InvoiceTypeCatalog::fromSnapshot($this->snapshot(
            IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE,
            ['type' => 'pos_cashier_invoice'],
        ));

        $this->assertSame(ElectronicDocumentKind::PosCashierInvoice, $type->kind);
        $this->assertNull($type->typeCode);
        $this->assertNull($type->transactionCode);
    }

    public function test_pos_invoice_preserves_explicit_subtype_without_inventing_type_code(): void
    {
        $type = InvoiceTypeCatalog::fromSnapshot($this->snapshot(
            IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE,
            ['type' => 'pos_cashier_invoice', 'tax_document_subtype' => 'simplified'],
        ));

        $this->assertSame(ElectronicDocumentKind::PosCashierInvoice, $type->kind);
        $this->assertNull($type->typeCode);
        $this->assertSame(InvoiceTransactionCode::Simplified, $type->transactionCode);
    }

    public function test_standard_simplified_distinction_uses_transaction_code_enum(): void
    {
        $this->assertSame('0100000', InvoiceTransactionCode::Standard->value);
        $this->assertSame('0200000', InvoiceTransactionCode::Simplified->value);
        $this->assertTrue(InvoiceTransactionCode::Standard->isStandard());
        $this->assertTrue(InvoiceTransactionCode::Simplified->isSimplified());

        $standard = new InvoiceType(
            ElectronicDocumentKind::TaxInvoice,
            InvoiceTypeCode::TaxInvoice,
            InvoiceTransactionCode::Standard,
        );
        $simplified = new InvoiceType(
            ElectronicDocumentKind::SimplifiedTaxInvoice,
            InvoiceTypeCode::TaxInvoice,
            InvoiceTransactionCode::Simplified,
        );

        $this->assertTrue($standard->isStandard());
        $this->assertTrue($simplified->isSimplified());
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function snapshot(string $sourceType, array $document): IssuedDocumentSnapshot
    {
        $snapshot = new IssuedDocumentSnapshot;
        $snapshot->forceFill([
            'id' => 1,
            'workspace_id' => 1,
            'source_type' => $sourceType,
            'source_id' => 1,
            'document_number' => 'DOC-1',
            'payload' => ['document' => $document],
        ]);

        return $snapshot;
    }
}
