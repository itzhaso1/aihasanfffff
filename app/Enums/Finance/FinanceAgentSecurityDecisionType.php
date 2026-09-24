<?php

namespace App\Enums\Finance;

enum FinanceAgentSecurityDecisionType: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Invalid = 'invalid';
    case RateLimited = 'rate_limited';
}
