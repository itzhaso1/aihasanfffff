<?php

declare(strict_types=1);

namespace App\AI\Training;

final readonly class TrainingSourceFile
{
    /**
     * @param  string  $relativePath  Library-relative path with forward slashes.
     * @param  string  $absolutePath  Resolved path; stays inside the library layer.
     */
    public function __construct(
        public string $relativePath,
        public TrainingType $folderType,
        public string $absolutePath,
    ) {}
}
