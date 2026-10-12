<?php

namespace App\Services\AI\Inbox\Persona;

use App\Models\AiSetting;
use App\Services\AI\AssistantName;

/**
 * Resolves workspace-scoped persona configuration from AI settings.
 */
final class InboxAgentPersonaResolver
{
    public function __construct(
        private readonly InboxAgentPersonaValidator $validator,
    ) {}

    /**
     * @return array{persona: InboxAgentPersona, source: string}
     */
    public function resolve(AiSetting $setting): array
    {
        $raw = $setting->agent_persona;
        if (! is_array($raw) || $raw === []) {
            $persona = InboxAgentPersonaDefaults::make();
            $source = 'default';
        } else {
            $persona = $this->validator->validate($raw);
            $source = 'workspace';
        }

        $configuredName = AssistantName::configured($setting);
        if ($configuredName !== null) {
            $persona = $persona->withIdentityName($configuredName);
        }

        return [
            'persona' => $persona,
            'source' => $source,
        ];
    }
}
