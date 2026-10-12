<?php

declare(strict_types=1);

namespace App\AI\Constitution;

/**
 * One validated constitution file: normalized text plus its content hash.
 */
final readonly class ConstitutionDocument
{
    public function __construct(
        public ConstitutionManifestEntry $entry,
        public string $content,
        public int $bytes,
        public string $hash,
    ) {}
}
