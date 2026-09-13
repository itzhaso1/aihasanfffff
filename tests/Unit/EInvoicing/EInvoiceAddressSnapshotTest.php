<?php

namespace Tests\Unit\EInvoicing;

use App\EInvoicing\EInvoiceAddress;
use Tests\TestCase;

class EInvoiceAddressSnapshotTest extends TestCase
{
    public function test_nested_address_is_preferred_over_flat_keys(): void
    {
        $address = EInvoiceAddress::fromSnapshotParty([
            'address' => [
                'line' => 'Free text',
                'street' => 'Nested Street',
                'building_number' => '12',
                'additional_number' => '3456',
                'district' => 'Nested District',
                'city' => 'Nested City',
                'postal_code' => '11111',
                'country_code' => 'SA',
            ],
            'street' => 'Flat Street',
            'city' => 'Flat City',
        ]);

        $this->assertSame('Free text', $address->line);
        $this->assertSame('Nested Street', $address->street);
        $this->assertSame('12', $address->buildingNumber);
        $this->assertSame('3456', $address->additionalNumber);
        $this->assertSame('Nested District', $address->district);
        $this->assertSame('Nested City', $address->city);
        $this->assertSame('11111', $address->postalCode);
        $this->assertSame('SA', $address->countryCode);
    }

    public function test_historical_flat_address_string_is_still_read(): void
    {
        $address = EInvoiceAddress::fromSnapshotParty([
            'address' => 'Old free text',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'country_code' => 'SA',
        ]);

        $this->assertSame('Old free text', $address->line);
        $this->assertSame('King Fahd Road', $address->street);
        $this->assertSame('1234', $address->buildingNumber);
        $this->assertSame('Riyadh', $address->city);
        $this->assertNull($address->additionalNumber);
    }

    public function test_missing_address_components_remain_null(): void
    {
        $address = EInvoiceAddress::fromSnapshotParty([
            'name' => 'Buyer',
            'address' => [
                'street' => 'Only Street',
            ],
        ]);

        $this->assertSame('Only Street', $address->street);
        $this->assertNull($address->line);
        $this->assertNull($address->buildingNumber);
        $this->assertNull($address->additionalNumber);
        $this->assertNull($address->district);
        $this->assertNull($address->city);
        $this->assertNull($address->postalCode);
        $this->assertNull($address->countryCode);
    }
}
