<?php

namespace App\Enums;

enum InboxAgentSecurityDecisionType: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case RequiresConfirmation = 'requires_confirmation';
    case RateLimited = 'rate_limited';
    case Invalid = 'invalid';
}
