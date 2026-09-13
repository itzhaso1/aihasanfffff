<?php

namespace App\EInvoicing\Security\Harness;

use App\EInvoicing\Security\CryptographicCertificate;
use App\EInvoicing\Security\CryptographicStamp;
use App\EInvoicing\Security\CryptographicStampResult;
use App\EInvoicing\Security\CryptographicStampSigner;
use App\EInvoicing\Security\Exceptions\CryptographicStampException;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\SigningAlgorithm;
use App\EInvoicing\Security\SigningInput;
use App\EInvoicing\Security\StampStatus;
use OpenSSLAsymmetricKey;

/**
 * TEST ONLY ECDSA signer driven by an explicit TestCryptographicProfile.
 * Must never be bound as the application CryptographicStampSigner.
 * Does not recompute invoice_hash. Does not persist private keys.
 */
final class TestProfileEcdsaSigner implements CryptographicStampSigner
{
    public function __construct(
        private readonly string $privateKeyPem,
        private readonly TestCryptographicProfile $profile,
        private readonly CryptographicProfileGuard $guard = new CryptographicProfileGuard,
    ) {
        if (trim($this->privateKeyPem) === '') {
            throw new CryptographicStampException(
                'Test profile signer requires an in-memory test private key.',
                operation: 'test_profile_sign',
                reason: 'missing_test_key',
            );
        }
        if (! str_contains($this->privateKeyPem, 'PRIVATE KEY')) {
            throw new CryptographicStampException(
                'Test profile signer requires PEM private key material marked for tests.',
                operation: 'test_profile_sign',
                reason: 'missing_test_key',
            );
        }
        $this->guard->assertProfileUsable($this->profile);
    }

    public function isProductionIdentity(): bool
    {
        return false;
    }

    public function isTestOnly(): bool
    {
        return true;
    }

    public function signCanonicalDigest(InvoiceHash $hash): CryptographicStampResult
    {
        $artifact = $this->signHash($hash);

        return CryptographicStampResult::testOnly(
            'TEST_ONLY:'.$this->profile->identifier(),
            base64_encode($artifact->bytes),
        );
    }

    public function sign(SigningInput $input): CryptographicStamp
    {
        $this->guard->assertSignerMayRun($this);
        $artifact = $this->signCertificate($input->invoiceHash, $input->certificate);

        return new CryptographicStamp(
            status: StampStatus::TestSigned,
            invoiceHash: $input->invoiceHash,
            signatureDerBase64: base64_encode($artifact->signature->bytes),
            publicKeySpkiDer: $input->certificate->publicKey->spkiDer,
            signatureAlgorithm: 'TEST_ONLY:'.$this->profile->identifier(),
            curve: SigningAlgorithm::TEST_CURVE,
            certificateFingerprint: $input->certificate->fingerprint,
            certificateId: null,
            signedInputIdentifier: $this->profile->signingInput->id(),
            productionIdentity: false,
        );
    }

    public function signHash(InvoiceHash $invoiceHash): SignatureArtifact
    {
        $this->guard->assertSignerMayRun($this);
        $payload = $this->profile->signingInput->payload($invoiceHash);
        $der = $this->opensslDer($payload);
        $encoded = $this->profile->encoding->encode($der);

        return new SignatureArtifact(
            invoiceHash: $invoiceHash,
            bytes: $encoded,
            encodingId: $this->profile->encoding->id(),
            signingInputId: $this->profile->signingInput->id(),
            profileIdentifier: $this->profile->identifier(),
        );
    }

    public function signCertificate(InvoiceHash $invoiceHash, CryptographicCertificate $certificate): TestCryptographicArtifact
    {
        if (! $certificate->testFixture) {
            throw new CryptographicStampException(
                'Test profile signer accepts test-fixture certificates only.',
                operation: 'test_profile_sign',
                reason: 'non_test_certificate',
            );
        }

        return new TestCryptographicArtifact(
            signature: $this->signHash($invoiceHash),
            publicKey: $certificate->publicKey,
            profileIdentifier: $this->profile->identifier(),
        );
    }

    public function verify(InvoiceHash $invoiceHash, SignatureArtifact $artifact, string $publicKeyPem): bool
    {
        if (! $artifact->invoiceHash->equals($invoiceHash)) {
            return false;
        }
        $payload = $this->profile->signingInput->payload($invoiceHash);
        $der = $this->profile->encoding->decode($artifact->bytes);
        $public = openssl_pkey_get_public($publicKeyPem);
        if (! $public instanceof OpenSSLAsymmetricKey) {
            return false;
        }

        return openssl_verify($payload, $der, $public, OPENSSL_ALGO_SHA256) === 1;
    }

    public function profile(): TestCryptographicProfile
    {
        return $this->profile;
    }

    private function opensslDer(string $payload): string
    {
        $key = openssl_pkey_get_private($this->privateKeyPem);
        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new CryptographicStampException(
                'Test private key is malformed.',
                operation: 'test_profile_sign',
                reason: 'malformed_test_key',
            );
        }

        $signature = '';
        if (! openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256) || $signature === '') {
            throw new CryptographicStampException(
                'Test ECDSA signature failed.',
                operation: 'test_profile_sign',
                reason: 'sign_failed',
            );
        }

        return $signature;
    }
}
