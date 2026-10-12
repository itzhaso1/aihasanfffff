<?php

namespace App\Services\AI\Gemini;

final class GeminiInteractionRequest
{
    /**
     * @param  string|list<array<string, mixed>>  $input
     * @param  list<array<string, mixed>>  $tools
     * @param  array<string, mixed>  $generationConfig
     */
    public function __construct(
        public readonly string $model,
        public readonly string|array $input,
        public readonly string $systemInstruction,
        public readonly array $tools,
        public readonly ?string $previousInteractionId = null,
        public readonly bool $store = true,
        public readonly array $generationConfig = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'model' => $this->model,
            'input' => $this->input,
            'store' => $this->store,
            'tools' => $this->tools,
        ];

        if ($this->systemInstruction !== '') {
            $payload['system_instruction'] = $this->systemInstruction;
        }

        if ($this->previousInteractionId !== null && $this->previousInteractionId !== '') {
            $payload['previous_interaction_id'] = $this->previousInteractionId;
        }

        if ($this->generationConfig !== []) {
            $payload['generation_config'] = $this->generationConfig;
        }

        return $payload;
    }
}
