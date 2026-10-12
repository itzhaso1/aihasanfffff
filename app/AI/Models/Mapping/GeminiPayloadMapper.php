<?php

declare(strict_types=1);

namespace App\AI\Models\Mapping;

use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Data\ToolExchange;
use App\AI\Models\Prompt\PromptMessageRenderer;
use App\AI\Models\Prompt\RenderedPrompt;
use App\AI\Models\ProviderId;
use App\AI\Models\ResolvedModelSettings;

final class GeminiPayloadMapper
{
    public function __construct(
        private readonly PromptMessageRenderer $renderer = new PromptMessageRenderer,
        private readonly ToolSchemaMapper $tools = new ToolSchemaMapper,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(ResolvedModelSettings $settings, ModelRequest $request): array
    {
        $rendered = $this->renderer->render($request->prompt);
        $payload = [
            'contents' => $this->contents($rendered, $request),
            'generationConfig' => [
                'temperature' => $settings->temperature,
                'maxOutputTokens' => $settings->maxOutputTokens,
            ],
        ];

        if ($rendered->system !== '') {
            $payload['system_instruction'] = [
                'parts' => [['text' => $rendered->system]],
            ];
        }

        $thinking = $this->thinkingBudget($settings);
        if ($thinking !== null) {
            $payload['generationConfig']['thinkingConfig'] = [
                'thinkingBudget' => $thinking,
                'includeThoughts' => false,
            ];
        }

        if ($request->tools !== []) {
            $declarations = $this->tools->geminiDeclarations($request->tools);
            if ($declarations !== []) {
                $payload['tools'] = [['functionDeclarations' => $declarations]];
            }
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contents(RenderedPrompt $rendered, ModelRequest $request): array
    {
        $contents = [[
            'role' => 'user',
            'parts' => [['text' => $rendered->user]],
        ]];

        foreach ($request->toolExchanges as $exchange) {
            $contents[] = [
                'role' => 'model',
                'parts' => [$this->functionCallPart($exchange->call)],
            ];
            $contents[] = [
                'role' => 'user',
                'parts' => [$this->functionResponsePart($exchange)],
            ];
        }

        return $this->mergeConsecutiveToolResponses($contents);
    }

    /**
     * @return array<string, mixed>
     */
    private function functionCallPart(ModelToolCall $call): array
    {
        $functionCall = [
            'name' => $call->name,
            'args' => (object) $call->arguments,
        ];
        if ($call->callId !== '') {
            $functionCall['id'] = $call->callId;
        }

        $part = ['functionCall' => $functionCall];
        $signature = $call->providerMetadata['thought_signature'] ?? null;
        if (is_string($signature) && trim($signature) !== '') {
            $part['thoughtSignature'] = trim($signature);
        }

        return $part;
    }

    /**
     * @return array<string, mixed>
     */
    private function functionResponsePart(ToolExchange $exchange): array
    {
        $success = ! isset($exchange->result['error']);
        $body = $success
            ? ['success' => true, 'data' => $exchange->result]
            : ['success' => false, 'error' => $exchange->result['error'] ?? 'tool_failed'];

        $functionResponse = [
            'name' => $exchange->call->name,
            'response' => $body,
        ];
        if ($exchange->call->callId !== '') {
            $functionResponse['id'] = $exchange->call->callId;
        }

        return ['functionResponse' => $functionResponse];
    }

    /**
     * @param  list<array<string, mixed>>  $contents
     * @return list<array<string, mixed>>
     */
    private function mergeConsecutiveToolResponses(array $contents): array
    {
        $merged = [];
        foreach ($contents as $entry) {
            $parts = $entry['parts'] ?? [];
            $first = is_array($parts[0] ?? null) ? $parts[0] : null;
            if (
                ($entry['role'] ?? '') === 'user'
                && is_array($first)
                && isset($first['functionResponse'])
                && $merged !== []
            ) {
                $previous = $merged[array_key_last($merged)];
                $previousFirst = is_array($previous['parts'][0] ?? null) ? $previous['parts'][0] : null;
                if (($previous['role'] ?? '') === 'user' && is_array($previousFirst) && isset($previousFirst['functionResponse'])) {
                    $previous['parts'] = array_merge($previous['parts'], $parts);
                    $merged[array_key_last($merged)] = $previous;

                    continue;
                }
            }
            $merged[] = $entry;
        }

        return $merged;
    }

    private function thinkingBudget(ResolvedModelSettings $settings): ?int
    {
        if ($settings->provider !== ProviderId::GEMINI || $settings->thinkingBudget === null) {
            return null;
        }
        if ($settings->thinkingBudget < 0 || ! str_starts_with($settings->model, 'gemini-2.5')) {
            return null;
        }

        return min($settings->thinkingBudget, intdiv($settings->maxOutputTokens, 2));
    }
}
