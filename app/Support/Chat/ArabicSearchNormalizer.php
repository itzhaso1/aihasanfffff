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
}
