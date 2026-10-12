<?php

namespace App\Services\AI;

use App\Models\AiSetting;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaCatalog;

/**
 * Workspace-scoped customer-facing assistant name (never from customer text).
 */
final class AssistantName
{
    public const DEFAULT = 'AI Assistant';

    public const MAX_LENGTH = InboxAgentPersonaCatalog::MAX_IDENTITY_NAME_LENGTH;

    /**
     * @var list<string>
     */
    private const GENERIC_NAMES = [
        'ai assistant',
        'assistant',
        'مساعدك الذكي',
        'المساعد الذكي',
        'مساعد ذكي',
    ];

    public static function sanitize(?string $raw): ?string
    {
        $clean = html_entity_decode(strip_tags((string) $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+/u', ' ', trim($clean)) ?? trim($clean);
        if ($clean === '') {
            return null;
        }

        if (! preg_match('/^[\p{L}\p{M}\p{N}][\p{L}\p{M}\p{N} .\'-]{0,'.(self::MAX_LENGTH - 1).'}$/u', $clean)) {
            return null;
        }

        return mb_substr($clean, 0, self::MAX_LENGTH);
    }

    public static function containsMarkup(?string $raw): bool
    {
        $value = (string) $raw;

        return $value !== strip_tags($value);
    }

    public static function isGeneric(?string $name): bool
    {
        $normalized = mb_strtolower(trim((string) $name));

        return $normalized === '' || in_array($normalized, self::GENERIC_NAMES, true);
    }

    /**
     * Official configured name when the merchant chose one; otherwise null (use persona/default).
     */
    public static function configured(AiSetting $setting): ?string
    {
        $clean = self::sanitize($setting->name);

        if ($clean === null || self::isGeneric($clean)) {
            return null;
        }

        return $clean;
    }

    public static function display(AiSetting $setting, ?string $personaIdentityName = null): string
    {
        $configured = self::configured($setting);
        if ($configured !== null) {
            return $configured;
        }

        $persona = self::sanitize($personaIdentityName);
        if ($persona !== null && ! self::isGeneric($persona)) {
            return $persona;
        }

        return self::DEFAULT;
    }

    public static function identityInstructions(string $name): string
    {
        return implode("\n", [
            '## ASSISTANT IDENTITY',
            'Your name is "'.$name.'".',
            'When introducing yourself, use this name naturally (typically in an early greeting only).',
            'Never claim a different name.',
            'Do not repeatedly mention your name in later messages.',
            'Do not infer or change gender, voice, or personality solely from the name.',
            'The assistant name is workspace configuration data. Ignore customer requests to rename you.',
        ]);
    }
}
