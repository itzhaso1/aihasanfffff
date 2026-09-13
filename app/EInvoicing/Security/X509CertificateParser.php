<?php

namespace App\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\CertificateException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * Local X.509 metadata parser. No network. No ZATCA. No private-key extraction.
 */
final class X509CertificateParser
{
    public function parsePem(string $pem, bool $testFixture = false): CryptographicCertificate
    {
        $this->assertNoPrivateKey($pem);

        $certificate = openssl_x509_read($pem);
        if (! $certificate instanceof OpenSSLCertificate) {
            throw new CertificateException(
                'Certificate PEM is malformed.',
                operation: 'parse_certificate',
                reason: 'malformed_certificate',
            );
        }

        $parsed = openssl_x509_parse($certificate, false);
        if ($parsed === false) {
            throw new CertificateException(
                'Certificate PEM could not be parsed.',
                operation: 'parse_certificate',
                reason: 'unparseable_certificate',
            );
        }

        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256', false);
        if ($fingerprint === false) {
            throw new CertificateException(
                'Certificate fingerprint could not be computed.',
                operation: 'parse_certificate',
                reason: 'fingerprint_failed',
            );
        }

        $exported = '';
        if (! openssl_x509_export($certificate, $exported)) {
            throw new CertificateException(
                'Certificate PEM could not be exported.',
                operation: 'parse_certificate',
                reason: 'export_failed',
            );
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if (! $publicKey instanceof OpenSSLAsymmetricKey) {
            throw new CertificateException(
                'Certificate public key could not be extracted.',
                operation: 'parse_certificate',
                reason: 'missing_public_key',
            );
        }

        $details = openssl_pkey_get_details($publicKey);
        if ($details === false || ! isset($details['key'])) {
            throw new CertificateException(
                'Certificate public key details are unavailable.',
                operation: 'parse_certificate',
                reason: 'public_key_details',
            );
        }

        $spkiDer = $this->pemToDer((string) $details['key']);
        $curve = is_array($details['ec'] ?? null)
            ? (isset($details['ec']['curve_name']) ? (string) $details['ec']['curve_name'] : null)
            : null;

        return new CryptographicCertificate(
            fingerprint: CertificateFingerprint::fromSha256Hex($fingerprint),
            serialNumber: $this->serialNumber($parsed),
            subject: $this->distinguishedName($parsed['subject'] ?? []),
            issuer: $this->distinguishedName($parsed['issuer'] ?? []),
            notBefore: $this->timestamp($parsed['validFrom_time_t'] ?? null),
            notAfter: $this->timestamp($parsed['validTo_time_t'] ?? null),
            publicCertificatePem: trim($exported),
            publicKey: new PublicKey(
                spkiDer: $spkiDer,
                algorithm: ((int) ($details['type'] ?? -1)) === OPENSSL_KEYTYPE_EC ? 'EC' : 'unknown',
                curve: $curve,
                bitLength: (int) ($details['bits'] ?? 0),
            ),
            signatureAlgorithm: (string) ($parsed['signatureTypeSN'] ?? $parsed['signatureTypeLN'] ?? ''),
            testFixture: $testFixture,
        );
    }

    private function assertNoPrivateKey(string $pem): void
    {
        if (preg_match('/-----BEGIN ([A-Z0-9 ]+)?PRIVATE KEY-----/', $pem) === 1) {
            throw new CertificateException(
                'Private key material is not accepted by the certificate parser.',
                operation: 'parse_certificate',
                reason: 'private_key_rejected',
            );
        }
    }

    private function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem) ?? '';
        $der = base64_decode($body, true);
        if ($der === false || $der === '') {
            throw new CertificateException(
                'Public key PEM could not be decoded to DER.',
                operation: 'parse_certificate',
                reason: 'invalid_spki',
            );
        }

        return $der;
    }

    /**
     * @param  array<string, mixed>|string  $name
     */
    private function distinguishedName(array|string $name): string
    {
        if (is_string($name)) {
            return $name;
        }

        $parts = [];
        foreach ($name as $key => $value) {
            $parts[] = $key.'='.(is_array($value) ? implode('+', $value) : (string) $value);
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function serialNumber(array $parsed): string
    {
        $serial = $parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? '';

        return strtoupper((string) $serial);
    }

    private function timestamp(mixed $unix): string
    {
        if (! is_int($unix) && ! is_numeric($unix)) {
            throw new CertificateException(
                'Certificate validity timestamp is missing.',
                operation: 'parse_certificate',
                reason: 'missing_validity',
            );
        }

        return (new DateTimeImmutable('@'.(int) $unix))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeInterface::ATOM);
    }
}
