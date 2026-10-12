<?php

namespace App\Services\AI\Inbox\Persona;

use App\Services\AI\AssistantName;

/**
 * Resolved conversational voice for one reply: persona plus the settled dialect,
 * language, emoji and greeting decisions. Rendering reads only from this object.
 */
final class InboxAgentVoice
{
    public function __construct(
        public readonly InboxAgentPersona $persona,
        public readonly string $dialect,
        public readonly string $language,
        public readonly bool $emojiAllowed,
        public readonly bool $firstContact,
        public readonly bool $introduceOnFirstContact,
    ) {}

    public function hasPersonalName(): bool
    {
        return ! AssistantName::isGeneric($this->persona->identityName);
    }
}
