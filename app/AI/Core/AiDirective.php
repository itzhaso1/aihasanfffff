<?php

declare(strict_types=1);

namespace App\AI\Core;

/**
 * Decision a context layer can hand back before any model call. Deterministic layers
 * (eligibility, handoff detection) own these decisions; the core only honours them.
 */
enum AiDirective: string
{
    case Respond = 'respond';
    case Skip = 'skip';
    case HandOff = 'hand_off';
}
