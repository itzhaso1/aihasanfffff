<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\AiProviderInterface;
use App\Services\AI\Providers\GoogleAiStudioProvider;
use App\Services\AI\Providers\OpenAiProvider;
use InvalidArgumentException;

class AiProviderManager
{
    public function resolve(?string $providerName = null): AiProviderInterface
    {
        $provider = $this->normalize($providerName ?? config('ai.default_provider', 'openai'));

        return match ($provider) {
            'openai' => app(OpenAiProvider::class),
            'google_ai_studio' => app(GoogleAiStudioProvider::class),
            default => throw new InvalidArgumentException("Unsupported AI provider [{$provider}]."),
        };
    }

    public function normalize(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'google', 'gemini', 'google-ai-studio', 'google_ai_studio' => 'google_ai_studio',
            'openai' => 'openai',
            default => strtolower(trim($provider)),
        };
    }
}
