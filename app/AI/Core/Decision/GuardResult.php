<?php

declare(strict_types=1);

namespace App\AI\Core\Decision;

use InvalidArgumentException;

final readonly class GuardResult
{
    public function __construct(
        public string $guard,
        public GuardVerdict $verdict,
        public ?string $reasonCode = null,
    ) {
        if (trim($guard) === '') {
            throw new InvalidArgumentException('Guard name must not be empty.');
        }
    }

    /**
     * @return array{guard: string, verdict: string, reason_code: ?string}
     */
    public function toArray(): array
    {
        return ['guard' => $this->guard, 'verdict' => $this->verdict->value, 'reason_code' => $this->reasonCode];
    }
}
