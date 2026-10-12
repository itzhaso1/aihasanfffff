<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Size bounds for the training library and for one selected training context.
 */
final readonly class TrainingLimits
{
    public const DEFAULT_MAX_FILE_BYTES = 24_576;

    public const MIN_DOCUMENT_CHARS = 50;

    public const DEFAULT_MAX_DOCUMENT_CHARS = 8_000;

    public const DEFAULT_MAX_TYPE_BYTES = 262_144;

    public const DEFAULT_MAX_LIBRARY_BYTES = 1_048_576;

    public const DEFAULT_CONTEXT_BUDGET_CHARS = 6_000;

    public const MIN_CONTEXT_BUDGET_CHARS = 500;

    public const MAX_CONTEXT_BUDGET_CHARS = 20_000;

    public function __construct(
        public int $maxFileBytes = self::DEFAULT_MAX_FILE_BYTES,
        public int $maxDocumentChars = self::DEFAULT_MAX_DOCUMENT_CHARS,
        public int $maxTypeBytes = self::DEFAULT_MAX_TYPE_BYTES,
        public int $maxLibraryBytes = self::DEFAULT_MAX_LIBRARY_BYTES,
    ) {
        if ($maxFileBytes < 1 || $maxDocumentChars < self::MIN_DOCUMENT_CHARS
            || $maxTypeBytes < $maxFileBytes || $maxLibraryBytes < $maxTypeBytes) {
            throw TrainingException::invalidLimits();
        }
    }
}
