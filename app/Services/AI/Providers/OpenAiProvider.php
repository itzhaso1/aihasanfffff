<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AiProviderInterface;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements AiProviderInterface
{
    public function supportsNativeToolCalling(): bool
    {
        return false;
    }

    public function generate(
        array $messages,
        string $model,
        float $temperature,
        int $maxTokens,
        array $toolDefinitions = [],
    ): array {
        $apiKey = config('services.openai.key');

        if (! $apiKey) {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ])
            ->throw()
            ->json();

        return [
            'content' => (string) ($response['choices'][0]['message']['content'] ?? ''),
            'tokens_used' => (int) ($response['usage']['total_tokens'] ?? 0),
            'raw' => is_array($response) ? $response : [],
        ];
    }
}
