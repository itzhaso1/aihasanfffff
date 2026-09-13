<?php

namespace Tests\Unit\EInvoicing\QR;

use App\EInvoicing\EInvoiceAddress;
use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\EInvoiceLine;
use App\EInvoicing\EInvoiceParty;
use App\EInvoicing\EInvoiceTax;
use App\EInvoicing\EInvoiceTotals;
use App\EInvoicing\InvoiceType;
use App\EInvoicing\QR\CryptographicQrFields;
use App\EInvoicing\QR\QrEncodingException;
use App\EInvoicing\QR\QrPayloadProfile;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\QR\QrTimestamp;
use App\EInvoicing\Security\InvoiceHash;
use App\Enums\EInvoicing\ComplianceStatus;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\ElectronicTaxClassification;
use App\Enums\EInvoicing\InvoiceTransactionCode;
use App\Enums\EInvoicing\InvoiceTypeCode;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Services\EInvoicing\QR\EInvoiceQrService;
use App\Services\Finance\Tax\TaxCalculationService;
use App\Services\Pos\PosTaxCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class EInvoiceQrServiceTest extends TestCase
{
    private const PERSISTED_INVOICE_HASH = 'UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=';

    #[Test]
    public function tag_1_copies_seller_name_from_the_immutable_document(): void
    {
        $payload = $this->service()->generate($this->document(), $this->hash());

        $this->assertSame('Issued Co', $payload->valueForTag(QrTag::SELLER_NAME));
    }

    #[Test]
    public function tag_2_copies_seller_vat_from_the_immutable_document(): void
    {
        $payload = $this->service()->generate($this->document(), $this->hash());

        $this->assertSame('310000000000003', $payload->valueForTag(QrTag::SELLER_VAT));
    }

    #[Test]
    public function tag_3_uses_issued_document_timestamp_and_is_deterministic(): void
    {
        $document = $this->document();
        $first = $this->service()->generate($document, $this->hash());
        $second = $this->service()->generate($document, $this->hash());

        $this->assertSame('2026-09-01T10:15:30', $first->valueForTag(QrTag::TIMESTAMP));
        $this->assertSame($first->valueForTag(QrTag::TIMESTAMP), $second->valueForTag(QrTag::TIMESTAMP));
        $this->assertSame($first->tlvBytes, $second->tlvBytes);
        $this->assertSame(QrTimestamp::fromDocument($document), $first->valueForTag(QrTag::TIMESTAMP));
    }

    #[Test]
    public function tag_4_and_5_copy_snapshot_totals_without_recalculating_tax(): void
    {
        $document = $this->document(
            tax: new EInvoiceTax(ElectronicTaxClassification::Standard, '15.00', '15.00', 'exclusive', null),
            totals: new EInvoiceTotals('100.00', '0.00', '100.00', '99.00', '250.00', '0.00', '250.00'),
        );

        $payload = $this->service()->generate($document, $this->hash());

        $this->assertSame('250.00', $payload->valueForTag(QrTag::TOTAL_WITH_VAT));
        $this->assertSame('99.00', $payload->valueForTag(QrTag::VAT_TOTAL));
        $this->assertNotSame('115.00', $payload->valueForTag(QrTag::TOTAL_WITH_VAT));
        $this->assertNotSame('15.00', $payload->valueForTag(QrTag::VAT_TOTAL));
    }

    #[Test]
    public function tag_6_equals_the_supplied_phase7_invoice_hash(): void
    {
        $hash = InvoiceHash::fromString(self::PERSISTED_INVOICE_HASH);
        $payload = $this->service()->generate($this->document(), $hash);

        $this->assertSame(self::PERSISTED_INVOICE_HASH, $payload->valueForTag(QrTag::INVOICE_HASH));
        $this->assertSame($hash->value(), $payload->valueForTag(QrTag::INVOICE_HASH));
    }

    #[Test]
    public function missing_required_seller_fields_fail_explicitly(): void
    {
        try {
            $this->service()->generate($this->document(sellerName: ''), $this->hash());
            $this->fail('Expected missing seller name to fail.');
        } catch (QrEncodingException $exception) {
            $this->assertStringContainsString('Seller name', $exception->getMessage());
        }

        try {
            $this->service()->generate($this->document(sellerVat: ''), $this->hash());
            $this->fail('Expected missing seller VAT to fail.');
        } catch (QrEncodingException $exception) {
            $this->assertStringContainsString('Seller VAT', $exception->getMessage());
        }

        $this->expectException(QrEncodingException::class);
        $this->expectExceptionMessage('issued-document timestamp');
        $this->service()->generate($this->document(issuedAt: null), $this->hash());
    }

    #[Test]
    public function phase8_production_path_does_not_fabricate_tags_7_to_9(): void
    {
        $payload = $this->service()->generate($this->document(), $this->hash());

        $this->assertSame(QrPayloadProfile::Phase8Unsigned, $payload->profile);
        $this->assertSame([1, 2, 3, 4, 5, 6], $payload->tagOrder());
        $this->assertFalse($payload->hasTag(QrTag::ECDSA_SIGNATURE));
        $this->assertFalse($payload->hasTag(QrTag::ECDSA_PUBLIC_KEY));
        $this->assertFalse($payload->hasTag(QrTag::ZATCA_CA_SIGNATURE));
        $this->assertFalse($payload->includesCryptographicTags());
        $this->assertNull($payload->valueForTag(QrTag::ECDSA_SIGNATURE));
    }

    #[Test]
    public function optional_cryptographic_test_artifacts_append_in_official_order(): void
    {
        $payload = $this->service()->generate(
            $this->document(),
            $this->hash(),
            new CryptographicQrFields('SIG7', 'KEY8', 'CA9'),
        );

        $this->assertSame(QrPayloadProfile::WithCryptographicFields, $payload->profile);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $payload->tagOrder());
        $this->assertSame('SIG7', $payload->valueForTag(QrTag::ECDSA_SIGNATURE));
        $this->assertSame('KEY8', $payload->valueForTag(QrTag::ECDSA_PUBLIC_KEY));
        $this->assertSame('CA9', $payload->valueForTag(QrTag::ZATCA_CA_SIGNATURE));
    }

    #[Test]
    public function repeated_generation_is_byte_identical(): void
    {
        $document = $this->document();
        $hash = $this->hash();
        $first = $this->service()->generate($document, $hash);
        $second = $this->service()->generate($document, $hash);

        $this->assertSame($first->tlvBytes, $second->tlvBytes);
        $this->assertSame($first->base64, $second->base64);
        $this->assertSame($first->valueForTag(QrTag::INVOICE_HASH), $second->valueForTag(QrTag::INVOICE_HASH));
    }

    #[Test]
    public function qr_layer_has_no_tax_engine_or_hash_service_collaborators(): void
    {
        $parameters = (new ReflectionClass(EInvoiceQrService::class))->getConstructor()?->getParameters() ?? [];
        $typeNames = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $parameters,
        );

        $this->assertNotContains(TaxCalculationService::class, $typeNames);
        $this->assertNotContains(PosTaxCalculator::class, $typeNames);
        $this->assertNotContains('App\\Services\\EInvoicing\\Security\\InvoiceHashService', $typeNames);

        $qrFiles = array_merge(
            glob(dirname(__DIR__, 4).'/app/EInvoicing/QR/*.php') ?: [],
            glob(dirname(__DIR__, 4).'/app/Services/EInvoicing/QR/*.php') ?: [],
        );
        $this->assertNotEmpty($qrFiles);
        foreach ($qrFiles as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringNotContainsString('TaxCalculationService', $source, $path);
            $this->assertStringNotContainsString('PosTaxCalculator', $source, $path);
            $this->assertStringNotContainsString('InvoiceHashService', $source, $path);
            $this->assertStringNotContainsString('Http::', $source, $path);
        }
    }

    private function service(): EInvoiceQrService
    {
        return new EInvoiceQrService;
    }

    private function hash(): InvoiceHash
    {
        return InvoiceHash::fromString(self::PERSISTED_INVOICE_HASH);
    }

    private function document(
        ?string $sellerName = 'Issued Co',
        ?string $sellerVat = '310000000000003',
        ?string $issuedAt = '2026-09-01T10:15:30+03:00',
        ?EInvoiceTax $tax = null,
        ?EInvoiceTotals $totals = null,
    ): EInvoiceDocument {
        return new EInvoiceDocument(
            workspaceId: 1,
            sourceSnapshotId: 99,
            sourceType: IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
            sourceId: 7,
            invoiceType: new InvoiceType(
                ElectronicDocumentKind::TaxInvoice,
                InvoiceTypeCode::TaxInvoice,
                InvoiceTransactionCode::Standard,
            ),
            documentNumber: 'INV-100',
            issueDate: '2026-09-01',
            issuedAt: $issuedAt,
            currency: 'SAR',
            seller: new EInvoiceParty(
                'company',
                $sellerName,
                $sellerVat,
                '1010000000',
                new EInvoiceAddress(null, '1234', 'King Fahd Road', 'Al Olaya', 'Riyadh', '12345', 'SA', '5678'),
                '0111111111',
                'seller@example.com',
            ),
            buyer: new EInvoiceParty(
                'customer',
                'E-Invoice Buyer',
                '300111111111113',
                null,
                new EInvoiceAddress(null, null, 'Buyer Street', 'Al Balad', 'Jeddah', '22222', 'SA', null),
                null,
                null,
            ),
            lines: [new EInvoiceLine(
                'خدمة فوترة',
                'خدمة فوترة',
                '1.000',
                '100.00',
                '0.00',
                '100.00',
                ElectronicTaxClassification::Standard,
                '15.00',
                '15.00',
                '115.00',
                null,
            )],
            tax: $tax ?? new EInvoiceTax(ElectronicTaxClassification::Standard, '15.00', '15.00', 'exclusive', null),
            totals: $totals ?? new EInvoiceTotals('100.00', '0.00', '100.00', '15.00', '115.00', '0.00', '115.00'),
            originalDocument: null,
            businessStatus: 'issued',
            paymentStatus: 'unpaid',
            complianceStatus: ComplianceStatus::Ready,
            payment: [],
            sourceMetadata: [],
        );
    }
}
