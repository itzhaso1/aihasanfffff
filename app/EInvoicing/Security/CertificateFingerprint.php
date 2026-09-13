<?php

namespace App\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\CertificateException;

final readonly class CertificateFingerprint
{
    private function __construct(private string $sha256Hex) {}

    public static function fromSha256Hex(string $hex): self
    {
        $normalized = strtolower(str_replace([':', ' '], '', trim($hex)));
        if (! preg_match('/^[0-9a-f]{64}$/', $normalized)) {
            throw new CertificateException(
                'Certificate fingerprint must be SHA-256 hex.',
                operation: 'fingerprint',
                reason: 'invalid_fingerprint',
            );
        }

        return new self($normalized);
    }

    public function value(): string
    {
        return $this->sha256Hex;
    }

    public function equals(self $other): bool
    {
        return $this->sha256Hex === $other->sha256Hex;
    }
}
