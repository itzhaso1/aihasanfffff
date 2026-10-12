<?php

namespace App\Services\AI\Providers\GoogleAiStudio;

/**
 * Builds Gemini `contents` from provider-neutral Inbox agent messages.
 */
final class GoogleAiStudioContentsMapper
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public function map(array $messages): array
    {
        $contents = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            if ($role === 'system') {
                continue;
            }

            if ($role === 'assistant' && is_array($message['tool_calls'] ?? null) && $message['tool_calls'] !== []) {
                $parts = [];
                $text = trim((string) ($message['content'] ?? ''));
                if ($text !== '') {
                    $parts[] = ['text' => $text];
                }

                foreach ($message['tool_calls'] as $call) {
                    if (! is_array($call)) {
                        continue;
                    }

                    $args = $call['arguments'] ?? [];
                    if (! is_array($args)) {
                        $args = [];
                    }

                    $functionCall = [
                        'name' => (string) ($call['name'] ?? ''),
                        'args' => (object) $args,
                    ];
                    $callId = trim((string) ($call['call_id'] ?? ''));
                    if ($callId !== '') {
                        $functionCall['id'] = $callId;
                    }

                    $part = ['functionCall' => $functionCall];
                    $thoughtSignature = $this->resolveThoughtSignature($call);
                    if ($thoughtSignature !== null) {
                        $part['thoughtSignature'] = $thoughtSignature;
                    }

                    $parts[] = $part;
                }

                if ($parts !== []) {
                    $contents[] = ['role' => 'model', 'parts' => $parts];
                }

                continue;
            }

            if ($role === 'tool') {
                $payload = json_decode((string) ($message['content'] ?? '{}'), true);
                if (! is_array($payload)) {
                    $payload = ['result' => (string) ($message['content'] ?? '')];
                }

                $responseBody = [
                    'success' => (bool) ($payload['success'] ?? false),
                    'data' => is_array($payload['result'] ?? null) ? $payload['result'] : ['value' => $payload['result'] ?? null],
                ];
                if (! ($payload['success'] ?? false)) {
                    $responseBody['error_code'] = $payload['error_code'] ?? null;
                }

                $functionResponse = [
                    'name' => (string) ($message['tool_name'] ?? ($payload['tool'] ?? 'tool')),
                    'response' => $responseBody,
                ];
                $toolCallId = trim((string) ($message['tool_call_id'] ?? ''));
                if ($toolCallId !== '') {
                    $functionResponse['id'] = $toolCallId;
                }

                $contents[] = [
                    'role' => 'user',
                    'parts' => [[
                        'functionResponse' => $functionResponse,
                    ]],
                ];

                continue;
            }

            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $geminiRole,
                'parts' => [
                    ['text' => (string) ($message['content'] ?? '')],
                ],
            ];
        }

        if ($contents === []) {
            $contents[] = [
                'role' => 'user',
                'parts' => [
                    ['text' => 'مرحبًا'],
                ],
            ];
        }

        return $this->mergeConsecutiveToolResponses($contents);
    }

    /**
     * @param  array<string, mixed>  $call
     */
    private function resolveThoughtSignature(array $call): ?string
    {
        $metadata = $call['provider_metadata'] ?? null;
        if (! is_array($metadata)) {
            return null;
        }

        $signature = $metadata['thought_signature'] ?? null;
        if (! is_string($signature)) {
            return null;
        }

        $signature = trim($signature);

        return $signature !== '' ? $signature : null;
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
            $firstPart = is_array($parts[0] ?? null) ? $parts[0] : null;

            if (
                ($entry['role'] ?? '') === 'user'
                && $firstPart
                && isset($firstPart['functionResponse'])
                && $merged !== []
            ) {
                $previous = $merged[count($merged) - 1];
                $previousFirst = is_array($previous['parts'][0] ?? null) ? $previous['parts'][0] : null;

                if (
                    ($previous['role'] ?? '') === 'user'
                    && $previousFirst
                    && isset($previousFirst['functionResponse'])
                ) {
                    $previous['parts'] = array_merge($previous['parts'], $parts);
                    $merged[count($merged) - 1] = $previous;

                    continue;
                }
            }

            $merged[] = $entry;
        }

        return $merged;
    }
}
