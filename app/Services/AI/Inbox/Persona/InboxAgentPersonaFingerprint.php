<?php

namespace App\Services\AI\Inbox\Persona;

final class InboxAgentPersonaFingerprint
{
    public function compute(InboxAgentPersona $persona): string
    {
        $payload = json_encode($persona->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return 'persona_v'.InboxAgentPersonaCatalog::SCHEMA_VERSION.'_'.substr(hash('sha256', $payload), 0, 16);
    }
}
