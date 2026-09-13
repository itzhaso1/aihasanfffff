<?php

namespace App\EInvoicing\Security\Harness;

/**
 * TEST ONLY combination of a signing-input candidate and a signature-encoding candidate.
 * Identifier is never a production ZATCA profile.
 */
final readonly class TestCryptographicProfile implements CryptographicProfile
{
    public function __construct(
        public TestSigningInputStrategy $signingInput,
        public TestSignatureEncoding $encoding,
    ) {}

    public static function invoiceHashDer(): self
    {
        return new self(new TestInvoiceHashSigningInput, new TestDerSignatureEncoding);
    }

    public static function invoiceHashP1363(): self
    {
        return new self(new TestInvoiceHashSigningInput, new TestP1363SignatureEncoding);
    }

    public static function signedInfoDer(): self
    {
        return new self(new TestSignedInfoSigningInput, new TestDerSignatureEncoding);
    }

    public static function signedInfoP1363(): self
    {
        return new self(new TestSignedInfoSigningInput, new TestP1363SignatureEncoding);
    }

    public function identifier(): string
    {
        return $this->signingInput->id().'+'.$this->encoding->id();
    }

    public function isTestOnly(): bool
    {
        return true;
    }

    public function isProductionZatca(): bool
    {
        return false;
    }
}
