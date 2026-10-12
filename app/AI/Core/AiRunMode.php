<?php

declare(strict_types=1);

namespace App\AI\Core;

/**
 * How a runtime result may be used. The runtime never delivers or persists anything
 * itself; the mode tells callers and every layer what they are allowed to do.
 */
enum AiRunMode: string
{
    case Live = 'live';
    case Preview = 'preview';
    case Evaluation = 'evaluation';

    /** Only a live run may produce a reply that a caller is allowed to deliver to a customer. */
    public function producesDeliverableReply(): bool
    {
        return $this === self::Live;
    }

    /** Preview and evaluation runs must stay free of production side effects in every layer. */
    public function isSandboxed(): bool
    {
        return $this !== self::Live;
    }

    public function requiresConversation(): bool
    {
        return $this === self::Live;
    }
}
