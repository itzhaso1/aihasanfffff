<?php

declare(strict_types=1);

namespace App\AI\Core\Decision;

use InvalidArgumentException;

final readonly class ModelUsage
{
    public function __construct(
        public ?string $provider = null,
        public ?string $model = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $calls = 1,
    ) {
        if ($inputTokens < 0 || $outputTokens < 0 || $calls < 0) {
            throw new InvalidArgumentException('Model usage counters must not be negative.');
        }
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function plus(self $other): self
    {
        return new self(
            provider: $other->provider ?? $this->provider,
            model: $other->model ?? $this->model,
            inputTokens: $this->inputTokens + $other->inputTokens,
            outputTokens: $this->outputTokens + $other->outputTokens,
            calls: $this->calls + $other->calls,
        );
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens(),
            'calls' => $this->calls,
        ];
    }
}
