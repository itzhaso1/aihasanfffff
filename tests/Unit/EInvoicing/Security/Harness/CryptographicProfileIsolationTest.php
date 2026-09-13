<?php

namespace Tests\Unit\EInvoicing\Security\Harness;

use App\EInvoicing\QR\Harness\QrTag9Provider;
use App\EInvoicing\QR\Harness\UnresolvedProductionQrTag9Provider;
use App\EInvoicing\Security\Exceptions\ProductionCryptographicProfileException;
use App\EInvoicing\Security\Harness\CryptographicProfile;
use App\EInvoicing\Security\Harness\CryptographicProfileGuard;
use App\EInvoicing\Security\Harness\TestCryptographicProfile;
use App\EInvoicing\Security\Harness\TestInvoiceHashSigningInput;
use App\EInvoicing\Security\Harness\TestProfileEcdsaSigner;
use App\EInvoicing\Security\Harness\UnresolvedProductionZatcaCryptographicProfile;
use App\Services\EInvoicing\Security\DeferredCryptographicStampSigner;
use App\Services\EInvoicing\Security\TestCryptographicStampSigner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CryptographicProfileIsolationTest extends TestCase
{
    #[Test]
    public function test_profiles_are_identifiable_and_never_production_zatca(): void
    {
        $profiles = [
            TestCryptographicProfile::invoiceHashDer(),
            TestCryptographicProfile::invoiceHashP1363(),
            TestCryptographicProfile::signedInfoDer(),
            TestCryptographicProfile::signedInfoP1363(),
        ];

        $ids = [];
        foreach ($profiles as $profile) {
            $this->assertTrue($profile->isTestOnly());
            $this->assertFalse($profile->isProductionZatca());
            $this->assertStringStartsWith('test.', $profile->identifier());
            $this->assertStringNotContainsString('production', $profile->identifier());
            $ids[] = $profile->identifier();
        }
        $this->assertCount(4, array_unique($ids));
        $this->assertSame('test.invoice_hash+test.der', TestCryptographicProfile::invoiceHashDer()->identifier());
        $this->assertSame('test.signed_info+test.p1363', TestCryptographicProfile::signedInfoP1363()->identifier());
    }

    #[Test]
    public function production_profile_cannot_resolve_to_a_test_profile_and_fails_loudly(): void
    {
        $production = new UnresolvedProductionZatcaCryptographicProfile;
        $this->assertTrue($production->isProductionZatca());
        $this->assertFalse($production->isTestOnly());
        $this->assertSame(UnresolvedProductionZatcaCryptographicProfile::IDENTIFIER, $production->identifier());

        $this->expectException(ProductionCryptographicProfileException::class);
        $this->expectExceptionMessage('Production ZATCA cryptographic profile is unresolved and unavailable.');
        $production->activate();
    }

    #[Test]
    public function guard_rejects_test_profiles_and_test_signers_in_production(): void
    {
        $guard = new CryptographicProfileGuard(treatAsProduction: true);
        $profile = TestCryptographicProfile::invoiceHashDer();

        try {
            $guard->assertProfileUsable($profile);
            $this->fail('Test profile must not be usable in production.');
        } catch (ProductionCryptographicProfileException $exception) {
            $this->assertStringContainsString('unresolved and unavailable', $exception->getMessage());
        }

        $nonProd = new CryptographicProfileGuard(treatAsProduction: false);
        $nonProd->assertProfileUsable(TestCryptographicProfile::invoiceHashDer());

        $key = $this->pem('test-only-egs-a.key.pem');
        $this->expectException(ProductionCryptographicProfileException::class);
        $guard->assertSignerMayRun(new TestCryptographicStampSigner($key));
    }

    #[Test]
    public function production_guard_rejects_unresolved_production_profile_without_fallback(): void
    {
        $this->expectException(ProductionCryptographicProfileException::class);
        (new CryptographicProfileGuard(treatAsProduction: false))
            ->assertProfileUsable(new UnresolvedProductionZatcaCryptographicProfile);
    }

    #[Test]
    public function test_profile_signer_is_test_only_and_not_a_zatca_identity(): void
    {
        $signer = new TestProfileEcdsaSigner(
            $this->pem('test-only-egs-a.key.pem'),
            TestCryptographicProfile::invoiceHashDer(),
            new CryptographicProfileGuard(treatAsProduction: false),
        );
        $this->assertTrue($signer->isTestOnly());
        $this->assertFalse($signer->isProductionIdentity());
        $this->assertFalse($signer->profile()->isProductionZatca());
        $this->assertInstanceOf(TestInvoiceHashSigningInput::class, $signer->profile()->signingInput);
    }

    #[Test]
    public function deferred_signer_is_neither_test_nor_production(): void
    {
        $deferred = new DeferredCryptographicStampSigner;
        $this->assertFalse($deferred->isTestOnly());
        $this->assertFalse($deferred->isProductionIdentity());
        (new CryptographicProfileGuard(treatAsProduction: false))->assertSignerMayRun($deferred);
    }

    #[Test]
    public function production_tag_9_provider_cannot_be_used_as_a_local_generator(): void
    {
        $this->assertInstanceOf(QrTag9Provider::class, new UnresolvedProductionQrTag9Provider);
        $this->expectException(ProductionCryptographicProfileException::class);
        (new UnresolvedProductionQrTag9Provider)->artifact();
    }

    #[Test]
    public function harness_and_profile_interfaces_are_not_production_zatca_implementations(): void
    {
        $this->assertTrue(interface_exists(CryptographicProfile::class));
        $source = (string) file_get_contents(dirname(__DIR__, 5).'/app/EInvoicing/Security/Harness/UnresolvedProductionZatcaCryptographicProfile.php');
        $this->assertStringContainsString('unresolved', strtolower($source));
        $this->assertStringNotContainsString('secp256k1 as production', strtolower($source));
    }

    private function pem(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/EInvoicing/'.$name);
    }
}
