<?php

namespace App\Services\AI\Providers\GoogleAiStudio;

/**
 * Translates Gemini generateContent responses into provider-neutral tool calls.
 */
final class GoogleAiStudioToolResponseMapper
{
    /**
     * @param  array<string, mixed>  $response
     * @return list<array{name:string,call_id:string,arguments:array<string,mixed>,provider_metadata:array<string,mixed>}>
     */
    public function extractToolCalls(array $response): array
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        if (! is_array($parts)) {
            return [];
        }

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
            $callId = $providerId !== '' ? $providerId : 'gemini_'.$name.'_'.$index;

            $providerMetadata = [
                'provider' => 'google_ai_studio',
                'part_index' => $index,
            ];
            $thoughtSignature = $part['thoughtSignature'] ?? $part['thought_signature'] ?? null;
            if (is_string($thoughtSignature) && trim($thoughtSignature) !== '') {
                $providerMetadata['thought_signature'] = trim($thoughtSignature);
            }

            $calls[] = [
                'name' => $name,
                'call_id' => $callId,
                'arguments' => $args,
                'provider_metadata' => $providerMetadata,
            ];

            $index++;
        }

        return $calls;
    }
}
