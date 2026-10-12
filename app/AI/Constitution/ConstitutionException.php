<?php

declare(strict_types=1);

namespace App\AI\Constitution;

use RuntimeException;

/**
 * Raised when the constitution cannot be loaded completely and safely. Messages name
 * the section and file only, never absolute paths or file contents.
 */
final class ConstitutionException extends RuntimeException
{
    public static function emptyManifest(): self
    {
        return new self('Invalid constitution manifest: at least one section is required.');
    }

    public static function invalidManifestEntry(): self
    {
        return new self('Invalid constitution manifest: entries must be ConstitutionManifestEntry instances.');
    }

    public static function duplicateManifestEntry(string $id): self
    {
        return new self('Invalid constitution manifest: section "'.$id.'" or its file is listed more than once.');
    }

    public static function invalidSectionId(): self
    {
        return new self('Invalid constitution manifest: section id must be a snake_case identifier.');
    }

    public static function invalidSectionFile(): self
    {
        return new self('Invalid constitution manifest: section file must be a plain lowercase .md file name.');
    }

    public static function invalidLimits(): self
    {
        return new self('Constitution limits must be positive and the total must cover at least one file.');
    }

    public static function missingDirectory(): self
    {
        return new self('The constitution directory does not exist.');
    }

    public static function missingFile(ConstitutionManifestEntry $entry): self
    {
        return new self(self::describe($entry).' is missing.');
    }

    public static function outsideRoot(ConstitutionManifestEntry $entry): self
    {
        return new self(self::describe($entry).' resolves outside the constitution directory.');
    }

    public static function unreadable(ConstitutionManifestEntry $entry): self
    {
        return new self(self::describe($entry).' cannot be read.');
    }

    public static function tooLarge(ConstitutionManifestEntry $entry, int $limit): self
    {
        return new self(self::describe($entry).' exceeds the '.$limit.' byte limit.');
    }

    public static function totalTooLarge(int $limit): self
    {
        return new self('The constitution exceeds the '.$limit.' byte total limit.');
    }

    public static function invalidEncoding(ConstitutionManifestEntry $entry): self
    {
        return new self(self::describe($entry).' is not valid UTF-8.');
    }

    public static function empty(ConstitutionManifestEntry $entry): self
    {
        return new self(self::describe($entry).' is empty.');
    }

    public static function executableContent(ConstitutionManifestEntry $entry): self
    {
        return new self(self::describe($entry).' contains executable code; constitution files are Markdown only.');
    }

    public static function duplicate(ConstitutionManifestEntry $entry, string $reason): self
    {
        return new self(self::describe($entry).' duplicates another section ('.$reason.').');
    }

    private static function describe(ConstitutionManifestEntry $entry): string
    {
        return 'Constitution section "'.$entry->id.'" ('.$entry->filename.')';
    }
}
