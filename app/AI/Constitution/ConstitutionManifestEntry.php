<?php

declare(strict_types=1);

namespace App\AI\Constitution;

/**
 * One constitution section and its file. The filename pattern admits a single lowercase
 * Markdown file name only, so separators, dots and parent references cannot appear.
 */
final readonly class ConstitutionManifestEntry
{
    private const ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const FILENAME_PATTERN = '/^[a-z][a-z0-9_-]*\.md$/';

    public function __construct(
        public string $id,
        public string $filename,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw ConstitutionException::invalidSectionId();
        }
        if (preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            throw ConstitutionException::invalidSectionFile();
        }
    }
}
