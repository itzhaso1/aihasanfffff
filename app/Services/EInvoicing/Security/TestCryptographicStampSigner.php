<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\CertificateFingerprint;
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
 * Local test signer. Not a ZATCA identity. Must never be bound as the
 * application CryptographicStampSigner in production service providers.
 */
final class TestCryptographicStampSigner implements CryptographicStampSigner
{
    public function __construct(
        private readonly string $privateKeyPem,
        private readonly ?string $expectedFingerprintHex = null,
    ) {
        if (trim($this->privateKeyPem) === '') {
            throw new CryptographicStampException(
                'Test signer requires an in-memory test private key.',
                operation: 'test_sign',
                reason: 'missing_test_key',
            );
        }
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
        $signature = $this->signDigest($hash->binary());

        return CryptographicStampResult::testOnly(
            SigningAlgorithm::TEST_PROFILE,
            base64_encode($signature),
        );
    }

    public function sign(SigningInput $input): CryptographicStamp
    {
        if ($this->expectedFingerprintHex !== null) {
            $expected = CertificateFingerprint::fromSha256Hex($this->expectedFingerprintHex);
            if (! $input->certificate->fingerprint->equals($expected)) {
                throw new CryptographicStampException(
                    'Test signer key does not match the supplied certificate fingerprint.',
                    documentIdentity: $input->documentIdentity,
                    operation: 'test_sign',
                    reason: 'certificate_key_mismatch',
                );
            }
        }

        $der = $this->signDigest($input->digestBytes());

        return new CryptographicStamp(
            status: StampStatus::TestSigned,
            invoiceHash: $input->invoiceHash,
            signatureDerBase64: base64_encode($der),
            publicKeySpkiDer: $input->certificate->publicKey->spkiDer,
            signatureAlgorithm: SigningAlgorithm::TEST_PROFILE,
            curve: SigningAlgorithm::TEST_CURVE,
            certificateFingerprint: $input->certificate->fingerprint,
            certificateId: null,
            signedInputIdentifier: $input->signedInputIdentifier(),
            productionIdentity: false,
        );
    }

    public function verify(string $digest, string $signatureDer, string $publicKeyPem): bool
    {
        $public = openssl_pkey_get_public($publicKeyPem);
        if (! $public instanceof OpenSSLAsymmetricKey) {
            return false;
        }

        $verified = openssl_verify($digest, $signatureDer, $public, OPENSSL_ALGO_SHA256);

        return $verified === 1;
    }

    private function signDigest(string $digest): string
    {
        $key = openssl_pkey_get_private($this->privateKeyPem);
        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new CryptographicStampException(
                'Test private key is malformed.',
                operation: 'test_sign',
                reason: 'malformed_test_key',
            );
        }

        $signature = '';
        if (! openssl_sign($digest, $signature, $key, OPENSSL_ALGO_SHA256) || $signature === '') {
            throw new CryptographicStampException(
                'Test ECDSA signature failed.',
                operation: 'test_sign',
                reason: 'sign_failed',
            );
        }

        return $signature;
    }
}
