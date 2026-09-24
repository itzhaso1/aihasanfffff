<?php

namespace App\Services\Media\Provider;

final class ProviderMediaDownloadResult
{
    public function __construct(
        public readonly string $binary,
        public readonly string $mimeType,
        public readonly ?string $originalFilename = null,
    ) {}
}
