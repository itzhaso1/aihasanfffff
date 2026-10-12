<?php

declare(strict_types=1);

namespace App\AI\Models\Mapping;

use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Decision\ModelUsage;
use App\AI\Models\ModelGatewayException;
use App\AI\Models\ProviderId;

final class OpenAiResponseNormalizer
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function normalize(array $response, string $model): ModelResponse
    {
        $choice = is_array($response['choices'][0] ?? null) ? $response['choices'][0] : null;
        if ($choice === null) {
            throw ModelGatewayException::emptyResponse();
        }

        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $calls = $this->toolCalls($message);
        $finish = $this->finishReason((string) ($choice['finish_reason'] ?? 'stop'), $calls);
        if ($finish === ModelFinishReason::Safety) {
            return new ModelResponse(null, [], $finish, $this->usage($response, $model));
        }

        $text = $this->text($message);

        return new ModelResponse($text, $calls, $finish, $this->usage($response, $model));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function text(array $message): ?string
    {
        $content = $message['content'] ?? null;
        if (is_string($content)) {
            $text = trim($content);

            return $text === '' ? null : $text;
        }
        if (! is_array($content)) {
            return null;
        }

        $chunks = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $text = trim($part);
                if ($text !== '') {
                    $chunks[] = $text;
                }

                continue;
            }
            if (! is_array($part)) {
                continue;
            }
            $type = strtolower((string) ($part['type'] ?? 'text'));
            if (in_array($type, ['reasoning', 'thought', 'thinking'], true)) {
                continue;
            }
            if ($type !== '' && $type !== 'text') {
                continue;
            }
            $text = trim((string) ($part['text'] ?? ''));
            if ($text !== '') {
                $chunks[] = $text;
            }
        }

        return $chunks === [] ? null : implode("\n", $chunks);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<ModelToolCall>
     */
    private function toolCalls(array $message): array
    {
        $raw = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
        $calls = [];
        foreach ($raw as $index => $call) {
            if (! is_array($call)) {
                continue;
            }
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $name = trim((string) ($function['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $arguments = $function['arguments'] ?? [];
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($arguments)) {
                $arguments = [];
            }
            $id = trim((string) ($call['id'] ?? ''));
            $calls[] = new ModelToolCall(
                callId: $id !== '' ? $id : 'openai_'.$name.'_'.$index,
                name: $name,
                arguments: $arguments,
                providerMetadata: ['provider' => ProviderId::OPENAI],
            );
        }

        return $calls;
    }

    /**
     * @param  list<ModelToolCall>  $calls
     */
    private function finishReason(string $reason, array $calls): ModelFinishReason
    {
        $normalized = strtolower($reason);

        return match (true) {
            $calls !== [] || $normalized === 'tool_calls' => ModelFinishReason::ToolCalls,
            in_array($normalized, ['content_filter', 'safety'], true) => ModelFinishReason::Safety,
            $normalized === 'length' => ModelFinishReason::Length,
            default => ModelFinishReason::Stop,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function usage(array $response, string $model): ModelUsage
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return new ModelUsage(
            provider: ProviderId::OPENAI,
            model: $model,
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
        );
    }
}
