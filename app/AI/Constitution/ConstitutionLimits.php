<?php

declare(strict_types=1);

namespace App\AI\Constitution;

/**
 * Size bounds for constitution files. Defaults leave ample room for concise laws while
 * rejecting accidental dumps of large documents into the top instruction layer.
 */
final readonly class ConstitutionLimits
{
    public const DEFAULT_MAX_FILE_BYTES = 16_384;

    public const DEFAULT_MAX_TOTAL_BYTES = 65_536;

    public function __construct(
        public int $maxFileBytes = self::DEFAULT_MAX_FILE_BYTES,
        public int $maxTotalBytes = self::DEFAULT_MAX_TOTAL_BYTES,
    ) {
        if ($maxFileBytes < 1 || $maxTotalBytes < $maxFileBytes) {
            throw ConstitutionException::invalidLimits();
        }
    }
}
