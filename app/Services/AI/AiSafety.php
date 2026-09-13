<?php

namespace App\Services\AI;

use InvalidArgumentException;

/**
 * Server-side AI policy: model allowlist, caps, redaction, untrusted output.
 * The model is never an authorization source.
 */
class AiSafety
{
    public function allowedProviders(): array
    {
        $configured = config('ai.allowed_providers', ['google_ai_studio', 'openai']);

        return array_values(array_filter(array_map('strval', is_array($configured) ? $configured : [])));
    }

    public function allowedModels(string $provider): array
    {
        $map = config('ai.allowed_models', []);
        $list = is_array($map[$provider] ?? null) ? $map[$provider] : [];

        return array_values(array_filter(array_map('strval', $list)));
    }

    public function resolveProvider(?string $requested): string
    {
        $normalized = app(AiProviderManager::class)->normalize((string) ($requested ?: config('ai.default_provider', 'google_ai_studio')));
        $allowed = $this->allowedProviders();
        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        $fallback = app(AiProviderManager::class)->normalize((string) config('ai.default_provider', 'google_ai_studio'));
        if (in_array($fallback, $allowed, true)) {
            return $fallback;
        }

        throw new InvalidArgumentException('No allowed AI provider is configured.');
    }

    public function resolveModel(string $provider, ?string $requested): string
    {
        $allowed = $this->allowedModels($provider);
        $candidate = trim((string) $requested);
        if ($candidate !== '' && in_array($candidate, $allowed, true)) {
            return $candidate;
        }

        $configured = (string) config('ai.'.$provider.'.model', '');
        if ($configured !== '' && ($allowed === [] || in_array($configured, $allowed, true))) {
            return $configured;
        }

        if ($allowed !== []) {
            return $allowed[0];
        }

        return $provider === 'google_ai_studio'
            ? (string) config('services.google_ai_studio.model', 'gemini-2.5-flash')
            : (string) config('ai.openai.model', 'gpt-4o-mini');
    }

    public function capMaxTokens(?int $requested): int
    {
        $cap = max(64, (int) config('ai.max_tokens_cap', 1024));
        $value = $requested !== null && $requested > 0 ? $requested : (int) config('ai.google_ai_studio.max_tokens', 512);

        return max(64, min($cap, $value));
    }

    public function capTemperature(?float $requested): float
    {
        $value = $requested ?? 0.3;
        if ($value < 0) {
            return 0.0;
        }

        return min(1.0, $value);
    }

    public function truncateInput(string $text, ?int $max = null): string
    {
        $limit = $max ?? (int) config('ai.max_input_characters', 8000);
        $limit = max(1, $limit);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit);
    }

    public function sanitizeReply(string $text): string
    {
        $clean = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text) ?? $text;
        $clean = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($clean);
    }

    public function storesContent(): bool
    {
        return (bool) config('ai.logging.store_content', false);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function logMetadata(
        string $provider,
        string $model,
        int $promptChars,
        string $outcome,
        array $extra = [],
    ): array {
        $payload = [
            'provider' => $provider,
            'model' => $model,
            'prompt_chars' => $promptChars,
            'outcome' => $outcome,
            'content_stored' => false,
        ];

        foreach ($extra as $key => $value) {
            if (in_array($key, ['messages', 'raw', 'content', 'prompt', 'system', 'output'], true)) {
                continue;
            }
            $payload[$key] = $value;
        }

        return $payload;
    }

    public function redactSecrets(string $text): string
    {
        $patterns = [
            '/sk-[A-Za-z0-9_\-]{10,}/',
            '/whsec_[A-Za-z0-9+\/=_\-]{8,}/',
            '/Bearer\s+[A-Za-z0-9\-_\.=]+/i',
            '/APP_KEY\s*=\s*\S+/i',
            '/DB_PASSWORD\s*=\s*\S+/i',
        ];

        $redacted = $text;
        foreach ($patterns as $pattern) {
            $redacted = preg_replace($pattern, '[REDACTED]', $redacted) ?? $redacted;
        }

        return $redacted;
    }
}
