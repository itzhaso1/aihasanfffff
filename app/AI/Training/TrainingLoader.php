<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Reads, parses and validates the whole library into a {@see TrainingIndex}.
 * Loading succeeds completely or throws; a broken library is never partially used.
 */
final class TrainingLoader
{
    private const FINGERPRINT_SCHEMA = 'hasim-training-files-v1';

    public function __construct(
        private readonly TrainingSource $source = new TrainingSource,
        private readonly TrainingDocumentFactory $factory = new TrainingDocumentFactory,
        private readonly TrainingLimits $limits = new TrainingLimits,
    ) {}

    /**
     * @param  ?string  $directory  Set by code only (defaults to the library directory); never taken from input.
     */
    public static function forDirectory(?string $directory = null, ?TrainingLimits $limits = null): self
    {
        $limits ??= new TrainingLimits;

        return new self(new TrainingSource($limits, $directory), new TrainingDocumentFactory($limits), $limits);
    }

    public function load(): TrainingIndex
    {
        $scan = $this->source->scan();
        if ($scan->problems !== []) {
            throw $scan->problems[0];
        }

        $documents = [];
        $typeBytes = [];
        $libraryBytes = 0;
        foreach ($scan->files as $file) {
            $raw = $this->source->read($file);
            $documents[] = $this->factory->create($file, $raw);

            $typeBytes[$file->folderType->value] = ($typeBytes[$file->folderType->value] ?? 0) + strlen($raw);
            $libraryBytes += strlen($raw);
            if ($typeBytes[$file->folderType->value] > $this->limits->maxTypeBytes) {
                throw TrainingException::tooLarge(TrainingIssueCode::TypeTooLarge, 'The '.$file->folderType->folder().' folder', $this->limits->maxTypeBytes);
            }
            if ($libraryBytes > $this->limits->maxLibraryBytes) {
                throw TrainingException::tooLarge(TrainingIssueCode::LibraryTooLarge, 'The training library', $this->limits->maxLibraryBytes);
            }
        }

        return new TrainingIndex($documents);
    }

    /**
     * Hash of every library file's path and raw bytes. Cheap to compute and exact, so a
     * cached index is reused only when no byte of the library changed.
     */
    public function fingerprint(): string
    {
        $scan = $this->source->scan();
        $lines = [self::FINGERPRINT_SCHEMA];
        foreach ($scan->files as $file) {
            $lines[] = $file->relativePath.'|'.hash('sha256', $this->source->read($file));
        }
        foreach ($scan->problems as $problem) {
            $lines[] = 'problem|'.$problem->getMessage();
        }

        return hash('sha256', implode("\n", $lines));
    }
}
