<?php

namespace App\EInvoicing\Security\Harness;

/**
 * Describes a cryptographic behavior profile.
 *
 * Test profiles are explicit and isolated. There is no production ZATCA
 * profile implementation — official curve, signing input, and encoding
 * remain unresolved (Phase 9A).
 */
interface CryptographicProfile
{
    public function identifier(): string;

    public function isTestOnly(): bool;

    public function isProductionZatca(): bool;
}
