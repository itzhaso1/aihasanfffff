<?php

namespace App\EInvoicing\Security\Csr;

use App\EInvoicing\Security\Exceptions\PrivateKeyStoreException;

/**
 * DEV/TEST ONLY CSR + key generation via OpenSSL. Not a production ZATCA profile.
 */
class DevTestCsrGenerator implements CsrGenerator
{
    /** @see \App\EInvoicing\Security\SigningAlgorithm::TEST_CURVE — DEV/TEST ONLY, not production policy */
    private const DEV_TEST_CURVE = 'secp256k1';

    private const FIXTURE_KEYS = [
        'tests/Fixtures/EInvoicing/test-only-egs-a.key.pem',
        'tests/Fixtures/EInvoicing/test-only-egs-b.key.pem',
    ];

    private const FIXTURE_CSRS = [
        'tests/Fixtures/EInvoicing/test-only-egs-a.csr.pem',
        'tests/Fixtures/EInvoicing/test-only-egs-b.csr.pem',
    ];

    private static int $fixtureKeyIndex = 0;

    /**
     * @return array{private_key_pem: string, csr_pem: string, public_key_fingerprint: string}
     */
    public function generate(CsrSubjectData $subject): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => self::DEV_TEST_CURVE,
        ]);
        if ($key === false) {
            // DEV/TEST fallback when OpenSSL EC curves are unavailable (e.g. some Windows builds).
            $key = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
        }
        $fixturePrivatePem = null;
        if ($key === false) {
            $fixture = $this->loadDevFixturePrivateKey();
            if ($fixture !== null) {
                $key = $fixture['key'];
                $fixturePrivatePem = $fixture['pem'];
            }
        }
        if ($key === false) {
            throw new PrivateKeyStoreException(
                'Dev/test key generation failed.',
                operation: 'csr_generate',
                reason: 'openssl_key_failed',
            );
        }

        if ($fixturePrivatePem !== null) {
            $privatePem = $fixturePrivatePem;
        } else {
            openssl_pkey_export($key, $privatePem);
        }

        $csr = openssl_csr_new($subject->distinguishedName(), $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            $csrPem = $this->loadFixtureCsrPem() ?? $this->devPlaceholderCsrPem($subject);
        } else {
            openssl_csr_export($csr, $csrPem);
        }

        $details = openssl_pkey_get_details($key);
        $fingerprint = is_array($details) && isset($details['key'])
            ? hash('sha256', $details['key'])
            : hash('sha256', (string) $privatePem);

        return [
            'private_key_pem' => (string) $privatePem,
            'csr_pem' => (string) $csrPem,
            'public_key_fingerprint' => $fingerprint,
        ];
    }

    /**
     * DEV/TEST ONLY — uses committed test fixtures when OpenSSL cannot generate keys in this environment.
     */
    /**
     * @return array{key: \OpenSSLAsymmetricKey, pem: string}|null
     */
    private function loadDevFixturePrivateKey(): ?array
    {
        if (! app()->environment(['local', 'testing']) && ! app()->runningUnitTests()) {
            return null;
        }

        $paths = self::FIXTURE_KEYS;
        $path = base_path($paths[self::$fixtureKeyIndex % count($paths)]);
        self::$fixtureKeyIndex++;

        if (! is_readable($path)) {
            return null;
        }

        $pem = file_get_contents($path);
        if (! is_string($pem) || trim($pem) === '') {
            return null;
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            return null;
        }

        return ['key' => $key, 'pem' => $pem];
    }

    private function loadFixtureCsrPem(): ?string
    {
        if (! app()->environment(['local', 'testing']) && ! app()->runningUnitTests()) {
            return null;
        }

        $index = (self::$fixtureKeyIndex - 1 + count(self::FIXTURE_CSRS)) % count(self::FIXTURE_CSRS);
        $path = base_path(self::FIXTURE_CSRS[$index]);
        if (! is_readable($path)) {
            return null;
        }

        $pem = file_get_contents($path);

        return is_string($pem) && str_contains($pem, 'BEGIN CERTIFICATE REQUEST') ? $pem : null;
    }

    /**
     * DEV/TEST ONLY when OpenSSL cannot build a PKCS#10 CSR on the host (observed on some Windows PHP builds).
     * Not a valid ZATCA CSR; replaced when real CSR files or OpenSSL CSR are available.
     */
    private function devPlaceholderCsrPem(CsrSubjectData $subject): string
    {
        $payload = base64_encode('hasem-dev-csr-placeholder:'.$subject->commonName.':'.bin2hex(random_bytes(8)));

        return "-----BEGIN CERTIFICATE REQUEST-----\n"
            .chunk_split($payload, 64)
            ."-----END CERTIFICATE REQUEST-----\n";
    }
}
