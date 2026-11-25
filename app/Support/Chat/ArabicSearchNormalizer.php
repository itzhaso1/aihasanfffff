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
        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $value = str_replace(['ـ', 'ٱ'], ['', 'ا'], $value);
        $value = str_replace(['أ', 'إ', 'آ', 'ء'], 'ا', $value);
        $value = str_replace('ى', 'ي', $value);
        $value = str_replace('ة', 'ه', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Deeper orthographic normalization for fuzzy matching (also folds hamza seats و/ي).
     */
    public function normalizeLoose(string $value): string
    {
        $base = $this->normalize($value);
        if ($base === '') {
            return '';
        }

        $base = str_replace(['ؤ', 'ئ'], ['و', 'ي'], $base);
        $base = str_replace(['گ', 'ک', 'چ', 'پ', 'ڤ', 'ژ'], ['ك', 'ك', 'ج', 'ب', 'ف', 'ز'], $base);

        return trim($base);
    }

    /**
     * Bounded typo-tolerant match between a customer token and a candidate catalog token.
     */
    public function isFuzzyTokenMatch(string $needle, string $candidate): bool
    {
        $a = $this->normalizeLoose($needle);
        $b = $this->normalizeLoose($candidate);

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b || (mb_strlen($a) >= 3 && (str_contains($b, $a) || str_contains($a, $b)))) {
            return true;
        }

        $lenA = mb_strlen($a);
        $lenB = mb_strlen($b);
        if ($lenA < 4 || $lenB < 4 || abs($lenA - $lenB) > 2) {
            return false;
        }

        // First character mismatch in very short Arabic words is a common false positive.
        if ($lenA <= 5 && mb_substr($a, 0, 1) !== mb_substr($b, 0, 1)) {
            return false;
        }

        $maxDist = max($lenA, $lenB) >= 7 ? 2 : 1;

        // Compare UTF-8 multibyte distance on transliterated codepoints so Arabic edit distance is accurate.
        $seqA = $this->codepointAsciiKey($a);
        $seqB = $this->codepointAsciiKey($b);

        return levenshtein($seqA, $seqB) <= $maxDist;
    }

    private function codepointAsciiKey(string $text): string
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $key = '';
        foreach ($chars as $char) {
            $ord = mb_ord($char, 'UTF-8');
            if ($ord >= 0x0621 && $ord <= 0x065F) {
                $key .= chr(65 + ($ord - 0x0621));
            } elseif ($ord >= 32 && $ord <= 126) {
                $key .= $char;
            } else {
                $key .= chr(97 + ($ord % 26));
            }
        }

        return $key;
    }
}
