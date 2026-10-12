<?php

declare(strict_types=1);

namespace App\AI\Models\Mapping;

use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Decision\ModelUsage;
use App\AI\Models\ModelGatewayException;
use App\AI\Models\ProviderId;

final class GeminiResponseNormalizer
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function normalize(array $response, string $model): ModelResponse
    {
        if (isset($response['promptFeedback']['blockReason']) && $response['promptFeedback']['blockReason'] !== '') {
            return new ModelResponse(null, [], ModelFinishReason::Safety, $this->usage($response, $model));
        }

        $candidate = is_array($response['candidates'][0] ?? null) ? $response['candidates'][0] : null;
        if ($candidate === null) {
            throw ModelGatewayException::emptyResponse();
        }

        $finish = $this->finishReason((string) ($candidate['finishReason'] ?? 'STOP'), $this->toolCalls($candidate));
        if ($finish === ModelFinishReason::Safety) {
            return new ModelResponse(null, [], $finish, $this->usage($response, $model));
        }

        $text = $this->text($candidate);
        $calls = $this->toolCalls($candidate);

        return new ModelResponse($text, $calls, $finish, $this->usage($response, $model));
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function text(array $candidate): ?string
    {
        $parts = is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [];
        $chunks = [];
        foreach ($parts as $part) {
            if (! is_array($part) || ! isset($part['text']) || $this->isThoughtPart($part) || $this->isNonCustomerPart($part)) {
                continue;
            }
            $text = trim((string) $part['text']);
            if ($text !== '') {
                $chunks[] = $text;
            }
        }

        return $chunks === [] ? null : implode("\n", $chunks);
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function isThoughtPart(array $part): bool
    {
        $thought = $part['thought'] ?? false;
        if ($thought === true || $thought === 1 || $thought === '1' || $thought === 'true') {
            return true;
        }

        $kind = strtolower((string) ($part['type'] ?? ''));

        return in_array($kind, ['thought', 'thinking', 'reasoning'], true);
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function isNonCustomerPart(array $part): bool
    {
        return isset($part['functionCall'])
            || isset($part['function_call'])
            || isset($part['functionResponse'])
            || isset($part['executableCode'])
            || isset($part['codeExecutionResult']);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<ModelToolCall>
     */
    private function toolCalls(array $candidate): array
    {
        $parts = is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [];
        $calls = [];
        $index = 0;
        foreach ($parts as $part) {
            if (! is_array($part) || ! isset($part['functionCall']) || ! is_array($part['functionCall'])) {
                continue;
            }
            $functionCall = $part['functionCall'];
            $name = trim((string) ($functionCall['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $args = $functionCall['args'] ?? [];
            if ($args instanceof \stdClass) {
                $args = (array) $args;
            }
            if (! is_array($args)) {
                $args = [];
            }
            $providerId = trim((string) ($functionCall['id'] ?? $part['id'] ?? ''));
            $metadata = ['provider' => ProviderId::GEMINI, 'part_index' => $index];
            $signature = $part['thoughtSignature'] ?? $part['thought_signature'] ?? null;
            if (is_string($signature) && trim($signature) !== '') {
                $metadata['thought_signature'] = trim($signature);
            }
            $calls[] = new ModelToolCall(
                callId: $providerId !== '' ? $providerId : 'gemini_'.$name.'_'.$index,
                name: $name,
                arguments: $args,
                providerMetadata: $metadata,
            );
            $index++;
        }

        return $calls;
    }

    /**
     * @param  list<ModelToolCall>  $calls
     */
    private function finishReason(string $reason, array $calls): ModelFinishReason
    {
        $normalized = strtoupper($reason);

        return match (true) {
            $calls !== [] => ModelFinishReason::ToolCalls,
            in_array($normalized, ['SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'RECITATION'], true) => ModelFinishReason::Safety,
            in_array($normalized, ['MAX_TOKENS', 'LENGTH'], true) => ModelFinishReason::Length,
            $normalized === 'STOP' || $normalized === '' => ModelFinishReason::Stop,
            default => ModelFinishReason::Stop,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function usage(array $response, string $model): ModelUsage
    {
        $meta = is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [];

        return new ModelUsage(
            provider: ProviderId::GEMINI,
            model: $model,
            inputTokens: (int) ($meta['promptTokenCount'] ?? 0),
            outputTokens: (int) ($meta['candidatesTokenCount'] ?? 0),
        );
    }
}
