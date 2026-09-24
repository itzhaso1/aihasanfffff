<?php

namespace App\Services\AI;

final class AiOutputValidator
{
    public function __construct(
        private readonly AiSafety $safety,
    ) {}

    /**
     * Returns sanitized outbound text or null when the reply must not be sent.
     */
    public function validateForOutbound(?string $reply): ?string
    {
        $clean = $this->safety->sanitizeReply(trim((string) $reply));
        if ($clean === '') {
            return null;
        }

        $max = max(64, (int) config('ai.max_output_characters', 4000));
        if (mb_strlen($clean) > $max) {
            $clean = mb_substr($clean, 0, $max);
        }

        if ($this->safety->redactSecrets($clean) !== $clean) {
            return null;
        }

        if ($this->looksLikeInternalFailure($clean)) {
            return null;
        }

        if ($this->looksLikeToolLeak($clean)) {
            return null;
        }

        return $clean;
    }

    private function looksLikeInternalFailure(string $text): bool
    {
        $markers = config('ai.reply.failure_markers', []);
        if (! is_array($markers)) {
            return false;
        }

        foreach ($markers as $marker) {
            if (is_string($marker) && $marker !== '' && str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeToolLeak(string $text): bool
    {
        if (str_starts_with(trim($text), '{') && str_contains($text, '"tool_call"')) {
            return true;
        }

        if (preg_match('/^SYSTEM:/iu', $text) === 1) {
            return true;
        }

        return false;
    }
}
