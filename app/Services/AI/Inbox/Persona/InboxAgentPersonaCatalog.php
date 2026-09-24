<?php

namespace App\Services\AI\Inbox\Persona;

/**
 * Allowed enum-like values for Inbox agent persona configuration.
 */
final class InboxAgentPersonaCatalog
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const TRAITS = [
        'friendly',
        'professional',
        'confident',
        'empathetic',
        'energetic',
        'calm',
        'concise',
        'helpful',
    ];

    /** @var list<string> */
    public const LANGUAGES = [
        'auto',
        'ar',
        'en',
    ];

    /** @var list<string> */
    public const DIALECTS = [
        'auto',
        'neutral',
        'jordanian',
        'gulf',
        'egyptian',
    ];

    /** @var list<string> */
    public const FORMALITY = [
        'casual',
        'casual_professional',
        'professional',
        'formal',
    ];

    /** @var list<string> */
    public const WARMTH = [
        'low',
        'medium',
        'high',
    ];

    /** @var list<string> */
    public const VERBOSITY = [
        'concise',
        'balanced',
        'detailed',
    ];

    /** @var list<string> */
    public const EMOJI_USAGE = [
        'none',
        'minimal',
        'moderate',
    ];

    /** @var list<string> */
    public const GREETING_STYLES = [
        'neutral',
        'friendly',
        'warm',
    ];

    /** @var list<string> */
    public const SALES_STYLES = [
        'neutral',
        'helpful_not_pushy',
        'consultative',
    ];

    /** @var list<string> */
    public const SUPPORT_STYLES = [
        'neutral',
        'empathetic_and_direct',
        'patient_and_detailed',
    ];

    public const MAX_TRAITS = 8;

    public const MAX_BEHAVIORAL_RULES = 12;

    public const MAX_RULE_LENGTH = 200;

    public const MAX_IDENTITY_NAME_LENGTH = 64;

    public const MAX_IDENTITY_ROLE_LENGTH = 128;

    /**
     * Allowed values for mobile/web forms. Laravel remains the validator.
     *
     * @return array<string, mixed>
     */
    public static function schemaForApi(): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'traits' => self::TRAITS,
            'languages' => self::LANGUAGES,
            'dialects' => self::DIALECTS,
            'formality' => self::FORMALITY,
            'warmth' => self::WARMTH,
            'verbosity' => self::VERBOSITY,
            'emoji_usage' => self::EMOJI_USAGE,
            'greeting_styles' => self::GREETING_STYLES,
            'sales_styles' => self::SALES_STYLES,
            'support_styles' => self::SUPPORT_STYLES,
            'max_traits' => self::MAX_TRAITS,
            'max_behavioral_rules' => self::MAX_BEHAVIORAL_RULES,
            'max_rule_length' => self::MAX_RULE_LENGTH,
            'max_identity_name_length' => self::MAX_IDENTITY_NAME_LENGTH,
            'max_identity_role_length' => self::MAX_IDENTITY_ROLE_LENGTH,
        ];
    }
}
