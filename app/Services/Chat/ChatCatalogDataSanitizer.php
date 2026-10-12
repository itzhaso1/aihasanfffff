<?php

namespace App\Services\Chat;

use Illuminate\Support\Arr;

/**
 * Customer-facing catalog content is data, not instructions. Keep it plain
 * text and restrict AI merchandising profiles to an explicit data schema.
 */
final class ChatCatalogDataSanitizer
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function aiSafeProfile(array $profile): array
    {
        $allowed = [
            'customer_summary',
            'selling_points',
            'common_customer_questions',
            'customer_terms',
            'synonyms',
            'prohibited_claims',
            'supported_claims',
            'pronunciation',
            'language_variants',
        ];

        return $this->sanitizeArray(Arr::only($profile, $allowed));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function customerTemplates(array $data): array
    {
        $keys = [
            'greeting',
            'unavailable',
            'out_of_stock',
            'low_stock',
            'business_closed',
            'support_contact',
            'order_followup',
            'inquiry_response',
        ];

        $templates = [];
        foreach (Arr::only($data, $keys) as $key => $translations) {
            if (! is_array($translations)) {
                continue;
            }
            $templates[$key] = [];
            foreach ($translations as $language => $text) {
                if (! is_string($language) || ! is_string($text)) {
                    continue;
                }
                $templates[$key][strtolower(trim($language))] = $this->plainText($text, 1000);
            }
        }

        return $templates;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitizeArray(array $data, int $maxStringLength = 2000): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || preg_match('/(?:prompt|instruction|tool|permission|credential|secret|token)/i', $key)) {
                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = $this->plainText($value, $maxStringLength);

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value, $maxStringLength);

                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public function plainText(?string $value, int $maxLength = 5000): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($value));
        $clean = trim((string) $clean);

        return mb_substr($clean, 0, $maxLength);
    }
}
