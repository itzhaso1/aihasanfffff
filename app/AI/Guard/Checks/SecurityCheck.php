<?php

declare(strict_types=1);

namespace App\AI\Guard\Checks;

use App\AI\Core\Data\GuardInput;
use App\AI\Core\Decision\SafeData;
use App\AI\Guard\Contracts\GuardCheck;
use App\AI\Guard\GuardFinding;
use App\Services\AI\AiSafety;

final class SecurityCheck implements GuardCheck
{
    public const NAME = 'security';

    public function __construct(
        private readonly AiSafety $safety = new AiSafety,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function inspect(GuardInput $input, string $text): GuardFinding
    {
        unset($input);

        if ($this->safety->redactSecrets($text) !== $text) {
            return GuardFinding::rejected(self::NAME, 'secret_leak');
        }

        if ($this->looksLikeToolLeak($text) || $this->looksLikeIdentityLeak($text)) {
            return GuardFinding::rejected(self::NAME, 'tool_or_identity_leak');
        }

        $sanitized = $this->safety->sanitizeReply($text);
        if ($sanitized === '') {
            return GuardFinding::rejected(self::NAME, 'empty_after_sanitize');
        }
        if ($sanitized !== $text) {
            return GuardFinding::repaired(self::NAME, $sanitized, 'markup_stripped');
        }

        return GuardFinding::pass(self::NAME, $text);
    }

    private function looksLikeToolLeak(string $text): bool
    {
        $trimmed = trim($text);
        if (str_starts_with($trimmed, '{') && (str_contains($trimmed, '"tool_call"') || str_contains($trimmed, '"tool_calls"') || str_contains($trimmed, '"functionCall"'))) {
            return true;
        }

        return preg_match('/^SYSTEM:/iu', $trimmed) === 1
            || str_contains($trimmed, 'functionCall')
            || str_contains($trimmed, 'thoughtSignature')
            || str_contains($trimmed, '<tool_call>');
    }

    private function looksLikeIdentityLeak(string $text): bool
    {
        if (preg_match('/\b(workspace_id|tenant_id|api_key|authorization)\b/i', $text) === 1) {
            return true;
        }

        return SafeData::text($text) !== $text && preg_match('/Bearer\s+\S+/i', $text) === 1;
    }
}
