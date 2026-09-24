<?php

namespace App\Services\AI\Inbox\Persona;

final class InboxAgentPersonaValidator
{
    /**
     * @param  array<string, mixed>|null  $raw
     */
    public function validate(?array $raw): InboxAgentPersona
    {
        if ($raw === null || $raw === []) {
            return InboxAgentPersonaDefaults::make();
        }

        if (! is_array($raw)) {
            throw InboxAgentPersonaValidationException::forField('structure', 'must be an object.');
        }

        $version = (int) ($raw['version'] ?? InboxAgentPersonaCatalog::SCHEMA_VERSION);
        if ($version !== InboxAgentPersonaCatalog::SCHEMA_VERSION) {
            throw InboxAgentPersonaValidationException::forField('version', 'unsupported schema version.');
        }

        $identity = $raw['identity'] ?? null;
        if (! is_array($identity)) {
            throw InboxAgentPersonaValidationException::forField('identity', 'must be an object.');
        }

        $name = $this->stringField($identity, 'name', InboxAgentPersonaCatalog::MAX_IDENTITY_NAME_LENGTH, required: true);
        $role = $this->stringField($identity, 'role', InboxAgentPersonaCatalog::MAX_IDENTITY_ROLE_LENGTH, required: true);

        $traits = $this->traits($raw['traits'] ?? []);

        $tone = $raw['tone'] ?? null;
        if (! is_array($tone)) {
            throw InboxAgentPersonaValidationException::forField('tone', 'must be an object.');
        }

        $language = $this->enumField($tone, 'language', InboxAgentPersonaCatalog::LANGUAGES);
        $dialect = $this->enumField($tone, 'dialect', InboxAgentPersonaCatalog::DIALECTS);
        $formality = $this->enumField($tone, 'formality', InboxAgentPersonaCatalog::FORMALITY);
        $warmth = $this->enumField($tone, 'warmth', InboxAgentPersonaCatalog::WARMTH);
        $verbosity = $this->enumField($tone, 'verbosity', InboxAgentPersonaCatalog::VERBOSITY);
        $emojiUsage = $this->enumField($tone, 'emoji_usage', InboxAgentPersonaCatalog::EMOJI_USAGE);

        $style = $raw['style'] ?? null;
        if (! is_array($style)) {
            throw InboxAgentPersonaValidationException::forField('style', 'must be an object.');
        }

        $greetingStyle = $this->enumField($style, 'greeting_style', InboxAgentPersonaCatalog::GREETING_STYLES);
        $salesStyle = $this->enumField($style, 'sales_style', InboxAgentPersonaCatalog::SALES_STYLES);
        $supportStyle = $this->enumField($style, 'support_style', InboxAgentPersonaCatalog::SUPPORT_STYLES);

        $behavioralRules = $this->behavioralRules($raw['behavioral_rules'] ?? []);

        return new InboxAgentPersona(
            version: $version,
            identityName: $name,
            identityRole: $role,
            traits: $traits,
            language: $language,
            dialect: $dialect,
            formality: $formality,
            warmth: $warmth,
            verbosity: $verbosity,
            emojiUsage: $emojiUsage,
            greetingStyle: $greetingStyle,
            salesStyle: $salesStyle,
            supportStyle: $supportStyle,
            behavioralRules: $behavioralRules,
        );
    }

    /**
     * @return list<string>
     */
    private function traits(mixed $value): array
    {
        if (! is_array($value)) {
            throw InboxAgentPersonaValidationException::forField('traits', 'must be a list.');
        }

        if (count($value) > InboxAgentPersonaCatalog::MAX_TRAITS) {
            throw InboxAgentPersonaValidationException::forField('traits', 'too many traits.');
        }

        $traits = [];
        foreach ($value as $trait) {
            if (! is_string($trait)) {
                throw InboxAgentPersonaValidationException::forField('traits', 'entries must be strings.');
            }
            $trait = trim($trait);
            if ($trait === '') {
                continue;
            }
            if (! in_array($trait, InboxAgentPersonaCatalog::TRAITS, true)) {
                throw InboxAgentPersonaValidationException::forField('traits', 'unsupported trait '.$trait.'.');
            }
            if (! in_array($trait, $traits, true)) {
                $traits[] = $trait;
            }
        }

        return $traits;
    }

    /**
     * @return list<string>
     */
    private function behavioralRules(mixed $value): array
    {
        if (! is_array($value)) {
            throw InboxAgentPersonaValidationException::forField('behavioral_rules', 'must be a list.');
        }

        if (count($value) > InboxAgentPersonaCatalog::MAX_BEHAVIORAL_RULES) {
            throw InboxAgentPersonaValidationException::forField('behavioral_rules', 'too many rules.');
        }

        $rules = [];
        foreach ($value as $rule) {
            if (! is_string($rule)) {
                throw InboxAgentPersonaValidationException::forField('behavioral_rules', 'entries must be strings.');
            }
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (mb_strlen($rule) > InboxAgentPersonaCatalog::MAX_RULE_LENGTH) {
                throw InboxAgentPersonaValidationException::forField('behavioral_rules', 'rule exceeds max length.');
            }
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $container
     */
    private function stringField(array $container, string $field, int $maxLength, bool $required): string
    {
        $value = $container[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            if ($required) {
                throw InboxAgentPersonaValidationException::forField($field, 'is required.');
            }

            return '';
        }

        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw InboxAgentPersonaValidationException::forField($field, 'exceeds max length.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $container
     * @param  list<string>  $allowed
     */
    private function enumField(array $container, string $field, array $allowed): string
    {
        $value = $container[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw InboxAgentPersonaValidationException::forField($field, 'is required.');
        }

        $value = trim($value);
        if (! in_array($value, $allowed, true)) {
            throw InboxAgentPersonaValidationException::forField($field, 'unsupported value.');
        }

        return $value;
    }
}
