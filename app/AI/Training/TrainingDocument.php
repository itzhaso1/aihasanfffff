<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * One validated training document. `source` is library-relative; absolute paths are
 * never exposed. `contentHash` covers metadata and body, `bodyHash` the body alone.
 */
final readonly class TrainingDocument
{
    /**
     * @param  list<string>  $includes  Profile documents only.
     */
    public function __construct(
        public string $id,
        public TrainingType $type,
        public int $version,
        public TrainingPriority $priority,
        public bool $always,
        public TrainingAppliesTo $appliesTo,
        public int $maxChars,
        public string $content,
        public string $contentHash,
        public string $bodyHash,
        public string $source,
        public ?string $title = null,
        public array $includes = [],
    ) {}

    public function characters(): int
    {
        return mb_strlen($this->content);
    }

    /** Short, stable version label: declared version plus content hash prefix. */
    public function versionLabel(): string
    {
        return $this->version.':'.substr($this->contentHash, 0, 16);
    }
}
