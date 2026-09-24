<?php

namespace App\Services\AI\Inbox\Memory;

/**
 * Rejects customer text that attempts to behave as system/agent instructions.
 */
final class InboxAgentMemoryInstructionGuard
{
    /** @var list<string> */
    private const PATTERNS = [
        '/ignore\s+(all\s+)?(previous|prior)\s+instructions/i',
        '/disregard\s+(the\s+)?(system|safety|rules)/i',
        '/you\s+must\s+always/i',
        '/reveal\s+(internal|secret|api|system)/i',
        '/\b\d+\s*%\s*discount\b/i',
        '/act\s+as\s+(system|admin|root)/i',
        '/تجاهل\s+(التعليمات|القواعد)/u',
        '/لا\s+تتبع/u',
        '/اكشف\s+(السر|المفتاح|النظام)/u',
    ];

    public function looksLikeInstruction(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
