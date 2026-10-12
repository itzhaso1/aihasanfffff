<?php

namespace App\Support\Chat;

/**
 * Search-only Arabic folding. Stored catalog text is never rewritten.
 */
final class ArabicSearchNormalizer
{
    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\p{M}+/u', '', $value) ?? $value;
        $value = str_replace(['ـ', 'ٱ'], ['', 'ا'], $value);
        $value = str_replace(['أ', 'إ', 'آ', 'ء'], 'ا', $value);
        $value = str_replace('ى', 'ي', $value);
        $value = str_replace('ة', 'ه', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
