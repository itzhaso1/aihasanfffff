<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

use InvalidArgumentException;

final readonly class ModelToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $providerMetadata  Opaque data a gateway needs to echo back; never interpreted or traced.
     */
    public function __construct(
        public string $callId,
        public string $name,
        public array $arguments = [],
        public array $providerMetadata = [],
    ) {
        if (trim($callId) === '' || trim($name) === '') {
            throw new InvalidArgumentException('Model tool calls need a call id and a name.');
        }
    }
}
