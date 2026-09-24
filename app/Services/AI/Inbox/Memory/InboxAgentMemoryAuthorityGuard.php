<?php

namespace App\Services\AI\Inbox\Memory;

/**
 * Rejects memory candidates that claim authority or attempt policy override.
 */
final class InboxAgentMemoryAuthorityGuard
{
    /** @var list<string> */
    private const PATTERNS = [
        '/\bi am (an )?admin\b/i',
        '/\bi own (this )?workspace\b/i',
        '/\ballow(ed)? to (refund|access|view all)/i',
        '/\breveal (all )?(customers|system prompt|secrets)/i',
        '/\b(can|may)\s+access\s+all\s+customers\b/i',
        '/\baccess\s+all\s+customers\b/i',
        '/\bignore (safety|payment|limits|rules)\b/i',
        '/\bremember that i am admin\b/i',
        '/\bأنا admin\b/u',
        '/\bأنا مسؤول\b/u',
        '/\bتجاهل (حدود|قواعد) (الدفع|الأمان)/u',
        '/\bاعرض(لي)? (كل )?العملاء/u',
    ];

    public function looksLikeAuthorityClaim(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
