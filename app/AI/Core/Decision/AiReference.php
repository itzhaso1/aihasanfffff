<?php

declare(strict_types=1);

namespace App\AI\Core\Decision;

use InvalidArgumentException;

/**
 * Opaque pointer to something a run referred to (for example a catalog item).
 * The core never interprets the type; layers that own the entity do.
 */
final readonly class AiReference
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            throw new InvalidArgumentException('Reference type must be a snake_case identifier.');
        }
        if (trim($id) === '') {
            throw new InvalidArgumentException('Reference id must not be empty.');
        }
    }

    /**
     * @return array{type: string, id: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
