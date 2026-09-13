<?php

namespace App\Services\Backup;

final readonly class BackupArtifact
{
    public function __construct(
        public string $relativePath,
        public string $absolutePath,
        public int $bytes,
        public string $sha256,
        public string $checksumRelativePath,
    ) {}
}
