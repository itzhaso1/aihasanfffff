<?php

namespace Tests\Unit\EInvoicing\Xml;

use App\EInvoicing\Xml\EInvoiceXmlMappingException;
use App\EInvoicing\Xml\UblTaxCategory;
use App\Enums\EInvoicing\ElectronicTaxClassification;
use PHPUnit\Framework\TestCase;

class UblTaxCategoryTest extends TestCase
{
    public function test_official_uncl5305_mapping(): void
    {
        $this->assertSame('S', UblTaxCategory::fromClassification(ElectronicTaxClassification::Standard)->value);
        $this->assertSame('Z', UblTaxCategory::fromClassification(ElectronicTaxClassification::ZeroRated)->value);
        $this->assertSame('E', UblTaxCategory::fromClassification(ElectronicTaxClassification::Exempt)->value);
        $this->assertSame('O', UblTaxCategory::fromClassification(ElectronicTaxClassification::OutOfScope)->value);
    }

    public function test_unspecified_classification_cannot_be_mapped(): void
    {
        $this->expectException(EInvoiceXmlMappingException::class);
        UblTaxCategory::fromClassification(ElectronicTaxClassification::Unspecified);
    }

    public function test_official_exemption_codes_are_the_zatca_list_only(): void
    {
        $this->assertTrue(UblTaxCategory::isOfficialExemptionCode('VATEX-SA-29'));
        $this->assertTrue(UblTaxCategory::isOfficialExemptionCode('VATEX-SA-OOS'));
        $this->assertFalse(UblTaxCategory::isOfficialExemptionCode('EXEMPT'));
        $this->assertFalse(UblTaxCategory::isOfficialExemptionCode('VATEX-SA-FAKE'));
    }
}
