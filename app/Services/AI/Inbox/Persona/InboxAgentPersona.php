<?php

namespace App\Services\AI\Inbox\Persona;

/**
 * Provider-neutral workspace agent persona (style and behavior, not security).
 */
final class InboxAgentPersona
{
    /**
     * @param  list<string>  $traits
     * @param  list<string>  $behavioralRules
     */
    public function __construct(
        public readonly int $version,
        public readonly string $identityName,
        public readonly string $identityRole,
        public readonly array $traits,
        public readonly string $language,
        public readonly string $dialect,
        public readonly string $formality,
        public readonly string $warmth,
        public readonly string $verbosity,
        public readonly string $emojiUsage,
        public readonly string $greetingStyle,
        public readonly string $salesStyle,
        public readonly string $supportStyle,
        public readonly array $behavioralRules,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'identity' => [
                'name' => $this->identityName,
                'role' => $this->identityRole,
            ],
            'traits' => $this->traits,
            'tone' => [
                'language' => $this->language,
                'dialect' => $this->dialect,
                'formality' => $this->formality,
                'warmth' => $this->warmth,
                'verbosity' => $this->verbosity,
                'emoji_usage' => $this->emojiUsage,
            ],
            'style' => [
                'greeting_style' => $this->greetingStyle,
                'sales_style' => $this->salesStyle,
                'support_style' => $this->supportStyle,
            ],
            'behavioral_rules' => $this->behavioralRules,
        ];
    }
}
