<?php

namespace App\Services\AI\Inbox\Persona;

/**
 * Settles persona vs catalog communication-profile style settings in code, so the
 * prompt carries one decision instead of conflicting raw preferences.
 *
 * Precedence: an explicit persona dialect wins; auto/gulf/saudi personas speak Saudi
 * Arabic unless the communication profile names a specific other dialect.
 */
final class InboxAgentVoiceResolver
{
    /** Communication-profile keys resolved here and never passed to the model raw. */
    public const VOICE_KEYS = [
        'arabic_dialect',
        'formality',
        'communication_style',
        'greeting_behavior',
        'response_length',
        'punctuation_style',
        'emoji_policy',
    ];

    private const SAUDI_DEFAULT_PERSONA_DIALECTS = ['auto', 'gulf', 'saudi'];

    private const GULF_VARIANTS = ['kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani'];

    private const OTHER_PROFILE_DIALECTS = ['jordanian', 'egyptian', 'levantine', 'iraqi', 'moroccan', 'algerian', 'tunisian'];

    /**
     * @param  array<string, mixed>  $communication  Catalog communication profile payload.
     */
    public function resolve(InboxAgentPersona $persona, array $communication, bool $firstContact): InboxAgentVoice
    {
        return new InboxAgentVoice(
            persona: $persona,
            dialect: $this->dialect($persona, $communication),
            language: $persona->language === 'en' ? 'en' : 'ar',
            emojiAllowed: $persona->emojiUsage !== 'none' && ($communication['emoji_policy'] ?? null) !== 'none',
            firstContact: $firstContact,
            introduceOnFirstContact: ($communication['greeting_behavior'] ?? null) !== 'disabled',
        );
    }

    /**
     * @param  array<string, mixed>  $context  Untrusted catalog context (with a nested "communication" key).
     * @return array<string, mixed>
     */
    public static function withoutVoiceSettings(array $context): array
    {
        if (is_array($context['communication'] ?? null)) {
            $context['communication'] = array_diff_key($context['communication'], array_flip(self::VOICE_KEYS));
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $communication
     */
    private function dialect(InboxAgentPersona $persona, array $communication): string
    {
        $personaDialect = $persona->dialect;
        if (! in_array($personaDialect, self::SAUDI_DEFAULT_PERSONA_DIALECTS, true)) {
            return $personaDialect;
        }
        if ($personaDialect === 'saudi') {
            return 'saudi';
        }

        $profileDialect = is_string($communication['arabic_dialect'] ?? null) ? $communication['arabic_dialect'] : '';
        if (in_array($profileDialect, self::GULF_VARIANTS, true)) {
            return $profileDialect;
        }
        if ($personaDialect === 'auto' && in_array($profileDialect, self::OTHER_PROFILE_DIALECTS, true)) {
            return $profileDialect;
        }

        return 'saudi';
    }
}
