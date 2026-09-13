<?php

namespace App\EInvoicing\Security;

/**
 * Local cryptographic-stamp result. This is not a ZATCA CSID identity.
 */
final readonly class CryptographicStampResult
{
    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_TEST_ONLY = 'test_only';

    private function __construct(
        public string $status,
        public ?string $algorithm,
        public ?string $value,
    ) {}

    public static function deferred(): self
    {
        return new self(self::STATUS_DEFERRED, null, null);
    }

    public static function testOnly(string $algorithm, string $value): self
    {
        return new self(self::STATUS_TEST_ONLY, $algorithm, $value);
    }

    public function isProductionIdentity(): bool
    {
        return false;
    }
}
