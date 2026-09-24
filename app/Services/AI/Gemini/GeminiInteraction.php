<?php

namespace App\Services\AI\Gemini;

final class GeminiInteraction
{
    /**
     * @param  list<GeminiFunctionCall>  $functionCalls
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $outputText,
        public readonly array $functionCalls,
        public readonly GeminiUsage $usage,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponse(array $payload): self
    {
        $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
        $calls = [];
        $textParts = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $type = (string) ($step['type'] ?? '');
            if ($type === 'function_call' || $type === 'functionCall') {
                $arguments = $step['arguments'] ?? $step['args'] ?? [];
                if (is_string($arguments)) {
                    $decoded = json_decode($arguments, true);
                    $arguments = is_array($decoded) ? $decoded : [];
                }
                if (! is_array($arguments)) {
                    $arguments = [];
                }

                $calls[] = new GeminiFunctionCall(
                    id: (string) ($step['id'] ?? $step['call_id'] ?? uniqid('fc_', true)),
                    name: (string) ($step['name'] ?? ''),
                    arguments: $arguments,
                );
            }

            foreach (self::textFromStep($step) as $text) {
                $textParts[] = $text;
            }
        }

        $output = trim((string) ($payload['output_text'] ?? $payload['outputText'] ?? ''));
        if ($output === '' && $textParts !== []) {
            $output = trim(implode("\n", $textParts));
        }

        $status = (string) ($payload['status'] ?? ($calls !== [] ? 'requires_action' : 'completed'));

        return new self(
            id: (string) ($payload['id'] ?? ''),
            status: $status,
            outputText: $output,
            functionCalls: $calls,
            usage: GeminiUsage::fromResponse($payload),
            raw: $payload,
        );
    }

    public function requiresAction(): bool
    {
        return $this->functionCalls !== [] || $this->status === 'requires_action';
    }

    /**
     * @param  array<string, mixed>  $step
     * @return list<string>
     */
    private static function textFromStep(array $step): array
    {
        $type = (string) ($step['type'] ?? '');
        $texts = [];

        if ($type === 'text' && isset($step['text'])) {
            $texts[] = (string) $step['text'];
        }

        if ($type === 'model_output' || $type === 'modelOutput') {
            if (isset($step['text'])) {
                $texts[] = (string) $step['text'];
            }
            $content = $step['content'] ?? [];
            if (is_string($content) && $content !== '') {
                $texts[] = $content;
            }
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (is_string($part) && $part !== '') {
                        $texts[] = $part;
                    }
                    if (is_array($part) && isset($part['text'])) {
                        $texts[] = (string) $part['text'];
                    }
                }
            }
        }

        return $texts;
    }
}
