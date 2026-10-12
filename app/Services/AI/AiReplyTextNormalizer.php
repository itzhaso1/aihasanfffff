<?php

namespace App\Services\AI;

/**
 * Deterministic cleanup of customer-facing AI replies: no Arabic diacritics, no
 * formal line-end periods in Arabic text, and no half word or half sentence when the
 * provider stopped at its token limit or the reply exceeds the outbound cap.
 */
final class AiReplyTextNormalizer
{
    /**
     * Harakat, tanwin, shadda, sukun, extended marks and dagger alif. U+0653–U+0655
     * (madda/hamza marks) are kept because decomposed text uses them to build letters.
     */
    private const DIACRITICS = '/[\x{064B}-\x{0652}\x{0656}-\x{065F}\x{0670}]/u';

    public function normalize(string $text, bool $truncated = false): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace(self::DIACRITICS, '', $text) ?? $text;
        $text = trim($text);
        if ($truncated) {
            $text = $this->dropIncompleteTail($text);
        }
        $text = preg_replace('/(?<=\p{Arabic})\.[ \t]+(?=\p{Arabic})/u', "\n", $text) ?? $text;
        $text = preg_replace('/(?<=\p{Arabic})[ \t]*[.،؛]+[ \t]*$/um', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    public function limit(string $text, int $maxCharacters): string
    {
        if (mb_strlen($text) <= $maxCharacters) {
            return $text;
        }

        return $this->dropIncompleteTail(mb_substr($text, 0, $maxCharacters));
    }

    /**
     * @param  array<string, mixed>  $providerResult
     */
    public static function providerStoppedAtLimit(array $providerResult): bool
    {
        $raw = is_array($providerResult['raw'] ?? null) ? $providerResult['raw'] : [];
        $finish = strtoupper((string) (data_get($raw, 'candidates.0.finishReason') ?? data_get($raw, 'choices.0.finish_reason') ?? ''));

        return in_array($finish, ['MAX_TOKENS', 'LENGTH'], true);
    }

    private function dropIncompleteTail(string $text): string
    {
        $text = rtrim($text);
        $lastBreak = mb_strrpos($text, "\n");
        if ($lastBreak !== false && trim(mb_substr($text, 0, $lastBreak)) !== '') {
            return rtrim(mb_substr($text, 0, $lastBreak));
        }

        if (preg_match('/^(.*[.!?؟])[^.!?؟]*$/su', $text, $matches) === 1 && trim($matches[1]) !== '') {
            return rtrim($matches[1]);
        }

        $lastSpace = mb_strrpos($text, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            return rtrim(mb_substr($text, 0, $lastSpace));
        }

        return $text;
    }
}
