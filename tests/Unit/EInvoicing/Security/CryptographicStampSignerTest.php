<?php

namespace Tests\Unit\EInvoicing\Security;

use App\EInvoicing\Security\CryptographicStampResult;
use App\EInvoicing\Security\HashAlgorithm;
use App\EInvoicing\Security\InvoiceHash;
use App\Services\EInvoicing\Security\DeferredCryptographicStampSigner;
use PHPUnit\Framework\TestCase;

class CryptographicStampSignerTest extends TestCase
{
    public function test_default_signer_is_deferred_and_is_not_a_zatca_identity(): void
    {
        $signer = new DeferredCryptographicStampSigner;
        $hash = InvoiceHash::fromBinary(HashAlgorithm::sha256Binary('digest'));
        $result = $signer->signCanonicalDigest($hash);

        $this->assertFalse($signer->isProductionIdentity());
        $this->assertFalse($signer->isTestOnly());
        $this->assertFalse($result->isProductionIdentity());
        $this->assertSame(CryptographicStampResult::STATUS_DEFERRED, $result->status);
        $this->assertNull($result->value);
    }

    public function test_test_only_hmac_is_not_a_production_zatca_stamp(): void
    {
        $hash = InvoiceHash::fromBinary(HashAlgorithm::sha256Binary('digest'));
        $signature = hash_hmac('sha256', $hash->value(), 'test-only-not-a-zatca-csid', true);
        $result = CryptographicStampResult::testOnly('HMAC-SHA256-TEST-ONLY', base64_encode($signature));

        $this->assertFalse($result->isProductionIdentity());
        $this->assertSame(CryptographicStampResult::STATUS_TEST_ONLY, $result->status);
        $this->assertNotSame(CryptographicStampResult::STATUS_DEFERRED, $result->status);
    }
}
