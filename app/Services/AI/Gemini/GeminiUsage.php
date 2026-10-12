<?php

namespace App\Services\AI\Gemini;

final class GeminiUsage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $totalTokens = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponse(array $payload): self
    {
        $usage = is_array($payload['usage'] ?? null)
            ? $payload['usage']
            : (is_array($payload['usage_metadata'] ?? null) ? $payload['usage_metadata'] : []);

        $input = (int) ($usage['total_input_tokens'] ?? $usage['input_tokens'] ?? $usage['promptTokenCount'] ?? 0);
        $output = (int) ($usage['total_output_tokens'] ?? $usage['output_tokens'] ?? $usage['candidatesTokenCount'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? $usage['totalTokenCount'] ?? 0);
        if ($total <= 0) {
            $total = $input + $output;
        }

        return new self(
            inputTokens: $input,
            outputTokens: $output,
            totalTokens: $total,
        );
    }

    public function plus(self $other): self
    {
        return new self(
            inputTokens: $this->inputTokens + $other->inputTokens,
            outputTokens: $this->outputTokens + $other->outputTokens,
            totalTokens: $this->totalTokens + $other->totalTokens,
        );
    }
}
