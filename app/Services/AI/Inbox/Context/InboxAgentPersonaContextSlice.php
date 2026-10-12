<?php

namespace App\Services\AI\Inbox\Context;

use App\Services\AI\Inbox\Persona\InboxAgentPersona;

final class InboxAgentPersonaContextSlice
{
    public function __construct(
        public readonly InboxAgentPersona $persona,
        public readonly string $source,
        public readonly string $fingerprint,
    ) {}
}
