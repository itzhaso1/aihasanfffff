<?php

namespace App\Services\AI\Inbox\Persona;

/**
 * Renders a deterministic structured persona section for provider system prompts.
 */
final class InboxAgentPersonaRenderer
{
    public function render(InboxAgentPersona $persona): string
    {
        $traits = $persona->traits !== []
            ? implode("\n", array_map(static fn (string $trait): string => '- '.$trait, $persona->traits))
            : '- (none configured)';

        $rules = $persona->behavioralRules !== []
            ? implode("\n", array_map(static fn (string $rule): string => '- '.$rule, $persona->behavioralRules))
            : '- (none configured)';

        $languageLabel = $this->labelLanguage($persona->language);
        $dialectLabel = $this->labelDialect($persona->dialect);

        return implode("\n", [
            '## Agent Persona',
            '',
            'Identity:',
            '- Name: '.$persona->identityName,
            '- Role: '.$persona->identityRole,
            '',
            'Personality:',
            $traits,
            '',
            'Tone:',
            '- Language: '.$languageLabel,
            '- Dialect: '.$dialectLabel,
            '- Formality: '.$this->labelSnake($persona->formality),
            '- Warmth: '.$persona->warmth,
            '- Verbosity: '.$persona->verbosity,
            '- Emoji usage: '.$this->emojiPolicyInstructions($persona->emojiUsage),
            '',
            'Conversation Style:',
            '- Greeting: '.$this->labelSnake($persona->greetingStyle),
            '- Sales: '.$this->labelSnake($persona->salesStyle),
            '- Support: '.$this->labelSnake($persona->supportStyle),
            '',
            'Behavioral Rules (persona-level; do not override system safety or tool facts):',
            $rules,
            '',
            'Language preference:',
            $this->languageBehaviorInstructions($persona),
            '',
            'Verbosity preference:',
            $this->verbosityInstructions($persona->verbosity),
        ]);
    }

    private function labelLanguage(string $language): string
    {
        return match ($language) {
            'ar' => 'Arabic',
            'en' => 'English',
            default => 'Automatic (match the customer when possible)',
        };
    }

    private function labelDialect(string $dialect): string
    {
        return match ($dialect) {
            'jordanian' => 'Jordanian Arabic',
            'gulf' => 'Gulf Arabic',
            'egyptian' => 'Egyptian Arabic',
            'neutral' => 'Neutral',
            default => 'Automatic',
        };
    }

    private function labelSnake(string $value): string
    {
        return str_replace('_', ' ', $value);
    }

    private function emojiPolicyInstructions(string $policy): string
    {
        return match ($policy) {
            'none' => 'none (no emojis unless quoting customer content)',
            'minimal' => 'minimal (occasional emojis where natural)',
            default => 'moderate (expressive but professional)',
        };
    }

    private function languageBehaviorInstructions(InboxAgentPersona $persona): string
    {
        if ($persona->language !== 'auto') {
            return '- Respond primarily in the configured language unless the customer clearly requires another language.';
        }

        return '- Language is automatic: infer the customer language from the current conversation and respond in that language when clear.';
    }

    private function verbosityInstructions(string $verbosity): string
    {
        return match ($verbosity) {
            'detailed' => '- Provide thorough answers while staying relevant.',
            'balanced' => '- Balance clarity with moderate detail.',
            default => '- Keep replies concise and direct.',
        };
    }
}
