<?php

declare(strict_types=1);

namespace App\AI\Constitution;

/**
 * The fixed, explicit load order of constitution sections. Order never depends on the
 * filesystem. The laws themselves live only in the Markdown files.
 */
final readonly class ConstitutionManifest
{
    /** @var list<ConstitutionManifestEntry> */
    public array $entries;

    /**
     * @param  list<ConstitutionManifestEntry>  $entries
     */
    public function __construct(array $entries)
    {
        if ($entries === []) {
            throw ConstitutionException::emptyManifest();
        }

        $ids = [];
        $files = [];
        foreach ($entries as $entry) {
            if (! $entry instanceof ConstitutionManifestEntry) {
                throw ConstitutionException::invalidManifestEntry();
            }
            if (isset($ids[$entry->id]) || isset($files[$entry->filename])) {
                throw ConstitutionException::duplicateManifestEntry($entry->id);
            }
            $ids[$entry->id] = true;
            $files[$entry->filename] = true;
        }

        $this->entries = array_values($entries);
    }

    public static function standard(): self
    {
        return new self([
            new ConstitutionManifestEntry('core', 'core.md'),
            new ConstitutionManifestEntry('truth', 'truth.md'),
            new ConstitutionManifestEntry('security', 'security.md'),
            new ConstitutionManifestEntry('privacy', 'privacy.md'),
            new ConstitutionManifestEntry('safety', 'safety.md'),
            new ConstitutionManifestEntry('handoff', 'handoff.md'),
            new ConstitutionManifestEntry('actions', 'actions.md'),
        ]);
    }

    /**
     * @return list<string>
     */
    public function filenames(): array
    {
        return array_map(static fn (ConstitutionManifestEntry $entry): string => $entry->filename, $this->entries);
    }
}
