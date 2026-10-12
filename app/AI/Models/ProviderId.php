<?php

declare(strict_types=1);

namespace App\AI\Models;

final class ProviderId
{
    public const GEMINI = 'google_ai_studio';

    public const OPENAI = 'openai';

    public static function normalize(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'google', 'gemini', 'google-ai-studio', 'google_ai_studio' => self::GEMINI,
            'openai' => self::OPENAI,
            default => strtolower(trim($provider)),
        };
    }
}
