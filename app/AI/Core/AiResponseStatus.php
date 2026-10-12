<?php

declare(strict_types=1);

namespace App\AI\Core;

enum AiResponseStatus: string
{
    /** A reply text was produced and passed the response guard. */
    case Replied = 'replied';

    /** The runtime decided not to answer. Nothing may be sent. */
    case Skipped = 'skipped';

    /** A human should take over. The runtime never performs or confirms the handoff itself. */
    case HandedOff = 'handed_off';

    /** Orchestration failed. Nothing may be sent and no fallback text exists. */
    case Failed = 'failed';
}
