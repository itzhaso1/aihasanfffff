<?php

namespace Tests\Unit\AI\Inbox\Persona;

use App\Services\AI\Inbox\Persona\InboxAgentPersonaCatalog;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaValidationException;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaValidator;
use Tests\TestCase;

class InboxAgentPersonaValidationTest extends TestCase
{
    private InboxAgentPersonaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(InboxAgentPersonaValidator::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'version' => 1,
            'identity' => ['name' => 'Sara', 'role' => 'Sales & Support Assistant'],
            'traits' => ['friendly'],
            'tone' => [
                'language' => 'ar',
                'dialect' => 'jordanian',
                'formality' => 'professional',
                'warmth' => 'medium',
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
    }

    public function test_invalid_language(): void
    {
        $payload = $this->validPayload();
        $payload['tone']['language'] = 'fr';

        $this->expectException(InboxAgentPersonaValidationException::class);
        $this->validator->validate($payload);
    }

    public function test_invalid_dialect(): void
    {
        $payload = $this->validPayload();
        $payload['tone']['dialect'] = 'maghrebi';

        $this->expectException(InboxAgentPersonaValidationException::class);
        $this->validator->validate($payload);
    }

    public function test_invalid_formality(): void
    {
        $payload = $this->validPayload();
        $payload['tone']['formality'] = 'ultra';

        $this->expectException(InboxAgentPersonaValidationException::class);
        $this->validator->validate($payload);
    }

    public function test_invalid_warmth(): void
    {
        $payload = $this->validPayload();
        $payload['tone']['warmth'] = 'extreme';

        $this->expectException(InboxAgentPersonaValidationException::class);
        $this->validator->validate($payload);
    }

    public function test_invalid_verbosity(): void
    {
        $payload = $this->validPayload();
        $payload['tone']['verbosity'] = 'novel';

        $this->expectException(InboxAgentPersonaValidationException::class);
        $this->validator->validate($payload);
    }

    public function test_invalid_emoji_policy(): void
    {
        $payload = $this->validPayload();
        $payload['tone']['emoji_usage'] = 'heavy';

        $this->expectException(InboxAgentPersonaValidationException::class);
        $this->validator->validate($payload);
    }

    public function test_too_many_traits(): void
    {
        $payload = $this->validPayload();
        $payload['traits'] = array_merge(InboxAgentPersonaCatalog::TRAITS, ['friendly']);

        $this->expectException(InboxAgentPersonaValidationException::class);
        $this->validator->validate($payload);
    }

    public function test_oversized_behavioral_rules(): void
    {
        $payload = $this->validPayload();
        $payload['behavioral_rules'] = array_fill(0, InboxAgentPersonaCatalog::MAX_BEHAVIORAL_RULES + 1, 'ok');

        $this->expectException(InboxAgentPersonaValidationException::class);
        $this->validator->validate($payload);
    }

    public function test_malformed_persona_structure(): void
    {
        $this->expectException(InboxAgentPersonaValidationException::class);
        $this->validator->validate(['version' => 1, 'identity' => 'bad']);
    }

    public function test_null_payload_returns_defaults(): void
    {
        $persona = $this->validator->validate(null);
        $this->assertSame('Assistant', $persona->identityName);
    }
}
