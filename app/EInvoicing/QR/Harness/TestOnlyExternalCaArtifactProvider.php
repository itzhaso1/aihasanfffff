<?php

namespace App\EInvoicing\QR\Harness;

/**
 * TEST ONLY synthetic stand-in for a future externally provisioned Tag 9.
 * This is NOT a ZATCA Technical CA signature and must never be treated as one.
 */
final class TestOnlyExternalCaArtifactProvider implements QrTag9Provider
{
    public const MARKER = 'TEST_ONLY_EXTERNAL_CA_ARTIFACT';

    public function __construct(
        private readonly string $fixtureBytes,
    ) {}

    public static function fromHex(string $hex): self
    {
        $bytes = hex2bin(preg_replace('/\s+/', '', $hex) ?? '');
        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException('Tag 9 test fixture hex is invalid.');
        }

        return new self($bytes);
    }

    public function artifact(): QrTag9Artifact
    {
        return new QrTag9Artifact(
            bytes: $this->fixtureBytes,
            marker: self::MARKER,
            syntheticTestFixture: true,
        );
    }
}
