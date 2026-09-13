<?php

namespace Tests\Unit\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\IcvAllocationException;
use App\EInvoicing\Security\Exceptions\InvoiceHashException;
use App\EInvoicing\Security\Exceptions\SecurityChainException;
use App\EInvoicing\Security\HashAlgorithm;
use App\EInvoicing\Security\Icv;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\Pih;
use PHPUnit\Framework\TestCase;

class SecurityValueObjectsTest extends TestCase
{
    public function test_first_document_pih_is_the_official_br_ksa_26_constant(): void
    {
        // Published BR-KSA-26 (19 May 2023) value, not read from Pih::FIRST_DOCUMENT.
        $published = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';
        $hexOfZero = hash('sha256', '0', false);
        $derived = base64_encode($hexOfZero);

        $this->assertSame('5feceb66ffc86f38d952786c6d696c79c2dbc239dd4e91b46729d73a27fb57e9', $hexOfZero);
        $this->assertSame($published, $derived);
        $this->assertSame($published, Pih::firstDocument()->value());
        $this->assertSame($published, Pih::FIRST_DOCUMENT);
        $this->assertTrue(Pih::firstDocument()->isFirstDocument());
        $this->assertSame($published, Pih::fromString($published)->value());
    }

    public function test_subsequent_pih_is_base64_of_binary_sha256_not_hex(): void
    {
        $hash = InvoiceHash::fromBinary(HashAlgorithm::sha256Binary('canonical-bytes'));
        $pih = Pih::fromInvoiceHash($hash);

        $this->assertSame($hash->value(), $pih->value());
        $this->assertNotSame(Pih::FIRST_DOCUMENT, $pih->value());
        $this->assertFalse($pih->isFirstDocument());
        $this->assertSame(32, strlen(base64_decode($pih->value(), true)));
    }

    public function test_empty_or_invented_pih_is_rejected(): void
    {
        $this->expectException(SecurityChainException::class);
        Pih::fromString('');
    }

    public function test_icv_is_a_positive_digit_counter(): void
    {
        $this->assertSame(1, Icv::fromInt(1)->value());
        $this->assertSame('10', Icv::fromString('10')->toXmlDigits());
        $this->assertSame(10, json_decode(json_encode(Icv::fromInt(10)), true));
    }

    public function test_icv_rejects_zero_negative_and_non_digits(): void
    {
        try {
            Icv::fromInt(0);
            $this->fail('Zero ICV was accepted.');
        } catch (IcvAllocationException) {
        }

        try {
            Icv::fromInt(-1);
            $this->fail('Negative ICV was accepted.');
        } catch (IcvAllocationException) {
        }

        $this->expectException(IcvAllocationException::class);
        Icv::fromString('01');
    }

    public function test_invoice_hash_is_canonical_base64_of_32_bytes(): void
    {
        $hash = InvoiceHash::fromBinary(HashAlgorithm::sha256Binary('payload'));
        $this->assertSame(44, strlen($hash->value()));
        $this->assertSame($hash->value(), InvoiceHash::fromString($hash->value())->value());
        $this->assertTrue($hash->equals(InvoiceHash::fromString($hash->value())));
    }

    public function test_invoice_hash_rejects_hex_or_short_strings(): void
    {
        $this->expectException(InvoiceHashException::class);
        InvoiceHash::fromString(hash('sha256', 'payload', false));
    }
}
