<?php

namespace App\Enums;

enum InboxAgentMemoryEvidenceType: string
{
    case Explicit = 'explicit';
    case Inferred = 'inferred';
}
