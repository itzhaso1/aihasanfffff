<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AiProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleAiStudioProvider implements AiProviderInterface
{
    public function generate(array $messages, string $model, float $temperature, int $maxTokens): array
    {
        $apiKey = config('services.google_ai_studio.key');
        if (! $apiKey) {
            throw new RuntimeException('Google AI Studio API key is not configured.');
        }

        $messageCollection = collect($messages);
        $systemInstruction = (string) ($messageCollection->firstWhere('role', 'system')['content'] ?? '');

        $contents = $messageCollection
            ->reject(fn (array $message): bool => ($message['role'] ?? '') === 'system')
            ->map(static fn (array $message): array => [
                'role' => ($message['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [
                    ['text' => (string) ($message['content'] ?? '')],
                ],
            ])
            ->values()
            ->all();

        if (count($contents) === 0) {
            $contents[] = [
                'role' => 'user',
                'parts' => [
                    ['text' => 'مرحبًا'],
                ],
            ];
        }

        $endpoint = rtrim((string) config('ai.google_ai_studio.base_url'), '/').'/models/'.$model.':generateContent';

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $maxTokens,
            ],
        ];

        if ($systemInstruction !== '') {
            $payload['system_instruction'] = [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ];
        }

        $response = Http::timeout(30)
            ->retry(2, 300)
            ->withHeaders([
                'x-goog-api-key' => $apiKey,
            ])
            ->post($endpoint, $payload)
            ->throw()
            ->json();

        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $text = collect($parts)
            ->pluck('text')
            ->filter()
            ->implode("\n");

        return [
            'content' => (string) $text,
            'tokens_used' => (int) ($response['usageMetadata']['totalTokenCount'] ?? 0),
            'raw' => $response,
        ];
    }
}
