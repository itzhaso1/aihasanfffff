<?php

declare(strict_types=1);

namespace App\AI\Core\Decision;

use InvalidArgumentException;

/**
 * An operation the application confirmed it executed. Only tool runners create these;
 * the model can never add one.
 */
final readonly class ConfirmedAction
{
    public function __construct(
        public string $type,
        public ?string $reference = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            throw new InvalidArgumentException('Action type must be a snake_case identifier.');
        }
    }

    /**
     * @return array{type: string, reference: ?string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'reference' => $this->reference];
    }
}
