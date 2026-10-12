<?php

namespace Tests\Unit\AI\Inbox\Persona;

use App\Services\AI\Inbox\Persona\InboxAgentPersona;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaDefaults;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaFingerprint;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaValidationException;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaValidator;
use Tests\TestCase;

class InboxAgentPersonaRepresentationTest extends TestCase
{
    public function test_default_persona_values(): void
    {
        $persona = InboxAgentPersonaDefaults::make();

        $this->assertSame('مشعل', $persona->identityName);
        $this->assertSame('Sales & Support', $persona->identityRole);
        $this->assertSame('ar', $persona->language);
        $this->assertSame('saudi', $persona->dialect);
        $this->assertSame('casual_professional', $persona->formality);
        $this->assertSame('high', $persona->warmth);
        $this->assertSame('concise', $persona->verbosity);
        $this->assertSame('none', $persona->emojiUsage);
    }

    public function test_custom_persona_round_trip(): void
    {
        $raw = [
            'version' => 1,
            'identity' => ['name' => 'Sara', 'role' => 'Sales & Support Assistant'],
            'traits' => ['friendly', 'confident'],
            'tone' => [
                'language' => 'ar',
                'dialect' => 'jordanian',
                'formality' => 'casual_professional',
                'warmth' => 'high',
                'verbosity' => 'concise',
                'emoji_usage' => 'light',
            ],
            'style' => [
                'greeting_style' => 'friendly',
                'sales_style' => 'helpful_not_pushy',
                'support_style' => 'empathetic_and_direct',
            ],
            'behavioral_rules' => ['Never invent prices.'],
        ];

        $this->expectException(InboxAgentPersonaValidationException::class);
        app(InboxAgentPersonaValidator::class)->validate($raw);
    }

    public function test_custom_persona_with_valid_emoji_policy(): void
    {
        $raw = [
            'version' => 1,
            'identity' => ['name' => 'Sara', 'role' => 'Sales & Support Assistant'],
            'traits' => ['friendly', 'confident'],
            'tone' => [
                'language' => 'ar',
                'dialect' => 'jordanian',
                'formality' => 'casual_professional',
                'warmth' => 'high',
                'verbosity' => 'concise',
                'emoji_usage' => 'minimal',
            ],
            'style' => [
                'greeting_style' => 'friendly',
                'sales_style' => 'helpful_not_pushy',
                'support_style' => 'empathetic_and_direct',
            ],
            'behavioral_rules' => ['Never invent prices.'],
        ];

        $persona = app(InboxAgentPersonaValidator::class)->validate($raw);

        $this->assertSame('Sara', $persona->identityName);
        $this->assertSame(['friendly', 'confident'], $persona->traits);
        $this->assertSame($raw, $persona->toArray());
    }

    public function test_deterministic_serialization_and_fingerprint(): void
    {
        $persona = InboxAgentPersonaDefaults::make();
        $fingerprint = app(InboxAgentPersonaFingerprint::class);

        $first = $fingerprint->compute($persona);
        $second = $fingerprint->compute(
            new InboxAgentPersona(
                version: $persona->version,
                identityName: $persona->identityName,
                identityRole: $persona->identityRole,
                traits: $persona->traits,
                language: $persona->language,
                dialect: $persona->dialect,
                formality: $persona->formality,
                warmth: $persona->warmth,
                verbosity: $persona->verbosity,
                emojiUsage: $persona->emojiUsage,
                greetingStyle: $persona->greetingStyle,
                salesStyle: $persona->salesStyle,
                supportStyle: $persona->supportStyle,
                behavioralRules: $persona->behavioralRules,
            )
        );

        $this->assertSame($first, $second);
        $this->assertStringStartsWith('persona_v1_', $first);
    }
}
