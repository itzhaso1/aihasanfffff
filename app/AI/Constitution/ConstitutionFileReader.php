<?php

declare(strict_types=1);

namespace App\AI\Constitution;

/**
 * Resolves and validates one constitution file inside the constitution root.
 *
 * Text is normalized before hashing (UTF-8 BOM removed, line endings unified, outer
 * whitespace trimmed) so a Git checkout with CRLF endings yields the same version.
 */
final class ConstitutionFileReader
{
    private const EXECUTABLE_MARKERS = ['<?php', '<?=', '<%'];

    public function __construct(private readonly ConstitutionLimits $limits) {}

    public function resolve(string $root, ConstitutionManifestEntry $entry): string
    {
        $path = realpath($root.DIRECTORY_SEPARATOR.$entry->filename);
        if ($path === false || ! is_file($path)) {
            throw ConstitutionException::missingFile($entry);
        }
        if (! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || dirname($path) !== $root) {
            throw ConstitutionException::outsideRoot($entry);
        }

        return $path;
    }

    public function read(string $path, ConstitutionManifestEntry $entry): ConstitutionDocument
    {
        if (! is_readable($path)) {
            throw ConstitutionException::unreadable($entry);
        }
        $size = filesize($path);
        if ($size === false) {
            throw ConstitutionException::unreadable($entry);
        }
        if ($size > $this->limits->maxFileBytes) {
            throw ConstitutionException::tooLarge($entry, $this->limits->maxFileBytes);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw ConstitutionException::unreadable($entry);
        }

        $content = $this->normalize($raw, $entry);

        return new ConstitutionDocument($entry, $content, strlen($raw), hash('sha256', $content));
    }

    private function normalize(string $raw, ConstitutionManifestEntry $entry): string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw ConstitutionException::invalidEncoding($entry);
        }

        $text = str_starts_with($raw, "\u{FEFF}") ? substr($raw, 3) : $raw;
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            throw ConstitutionException::empty($entry);
        }
        foreach (self::EXECUTABLE_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                throw ConstitutionException::executableContent($entry);
            }
        }

        return $text;
    }
}
