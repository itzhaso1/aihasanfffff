<?php

declare(strict_types=1);

namespace App\AI\Constitution;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\ConstitutionProvider;
use App\AI\Core\Data\LoadedConstitution;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;

/**
 * Loads the constitution from its Markdown files in manifest order.
 *
 * The version is a SHA-256 over the manifest order and each file's normalized content,
 * so any content change produces a new version and unchanged files keep theirs. A loaded
 * constitution is reused while every file keeps the same size, mtime and inode; any
 * change on disk triggers a full reload and revalidation. Loading either succeeds
 * completely or throws; there is no partial or empty fallback.
 */
final class ConstitutionLoader implements ConstitutionProvider
{
    public const SECTION_KEY_PREFIX = 'constitution.';

    public const SOURCE_PREFIX = 'constitution/';

    private const VERSION_SCHEMA = 'hasim-constitution-v1';

    private const SECTION_VERSION_LENGTH = 16;

    private readonly ConstitutionManifest $manifest;

    private readonly ConstitutionLimits $limits;

    private readonly ConstitutionFileReader $reader;

    private readonly string $directory;

    private ?LoadedConstitution $loaded = null;

    private ?string $loadedSignature = null;

    /**
     * @param  ?string  $directory  Set by code only (defaults to this directory); never taken from input.
     */
    public function __construct(
        ?ConstitutionManifest $manifest = null,
        ?ConstitutionLimits $limits = null,
        ?string $directory = null,
    ) {
        $this->manifest = $manifest ?? ConstitutionManifest::standard();
        $this->limits = $limits ?? new ConstitutionLimits;
        $this->reader = new ConstitutionFileReader($this->limits);
        $this->directory = $directory ?? __DIR__;
    }

    public function load(AiRequest $request): LoadedConstitution
    {
        return $this->current();
    }

    public function current(): LoadedConstitution
    {
        $root = $this->root();
        $paths = $this->resolvePaths($root);
        $signature = $this->signature($paths);

        if ($this->loaded !== null && $this->loadedSignature === $signature) {
            return $this->loaded;
        }

        $this->loaded = $this->build($this->readDocuments($paths));
        $this->loadedSignature = $signature;

        return $this->loaded;
    }

    private function root(): string
    {
        $root = realpath($this->directory);
        if ($root === false || ! is_dir($root)) {
            throw ConstitutionException::missingDirectory();
        }

        return $root;
    }

    /**
     * @return array<string, string> Section id => resolved path, in manifest order.
     */
    private function resolvePaths(string $root): array
    {
        $paths = [];
        foreach ($this->manifest->entries as $entry) {
            $path = $this->reader->resolve($root, $entry);
            if (in_array($path, $paths, true)) {
                throw ConstitutionException::duplicate($entry, 'same file');
            }
            $paths[$entry->id] = $path;
        }

        return $paths;
    }

    /**
     * @param  array<string, string>  $paths
     */
    private function signature(array $paths): string
    {
        $parts = [];
        foreach ($paths as $id => $path) {
            clearstatcache(true, $path);
            $stat = stat($path);
            $parts[] = $id.'|'.($stat === false ? 'missing' : $stat['size'].'|'.$stat['mtime'].'|'.$stat['ino']);
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<string, string>  $paths
     * @return list<ConstitutionDocument>
     */
    private function readDocuments(array $paths): array
    {
        $documents = [];
        $hashes = [];
        $total = 0;
        foreach ($this->manifest->entries as $entry) {
            $document = $this->reader->read($paths[$entry->id], $entry);
            if (isset($hashes[$document->hash])) {
                throw ConstitutionException::duplicate($entry, 'same content');
            }
            $total += $document->bytes;
            if ($total > $this->limits->maxTotalBytes) {
                throw ConstitutionException::totalTooLarge($this->limits->maxTotalBytes);
            }
            $hashes[$document->hash] = true;
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @param  list<ConstitutionDocument>  $documents
     */
    private function build(array $documents): LoadedConstitution
    {
        $sections = [];
        $fingerprint = [self::VERSION_SCHEMA];
        foreach ($documents as $document) {
            $sections[] = new PromptSection(
                layer: PromptLayer::Constitution,
                key: self::SECTION_KEY_PREFIX.$document->entry->id,
                content: $document->content,
                source: self::SOURCE_PREFIX.$document->entry->filename,
                version: substr($document->hash, 0, self::SECTION_VERSION_LENGTH),
            );
            $fingerprint[] = $document->entry->id.':'.$document->hash;
        }

        return new LoadedConstitution(hash('sha256', implode("\n", $fingerprint)), $sections);
    }
}
