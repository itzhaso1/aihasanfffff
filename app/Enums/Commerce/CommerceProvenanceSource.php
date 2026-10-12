<?php

namespace App\Enums\Commerce;

enum CommerceProvenanceSource: string
{
    case MerchantProvided = 'merchant_provided';
    case MerchantRule = 'merchant_rule';
    case CustomerExplicit = 'customer_explicit';
    case SystemDerived = 'system_derived';
    case AiInferred = 'ai_inferred';

    public function isAuthoritative(): bool
    {
        return $this === self::MerchantProvided || $this === self::MerchantRule;
    }
}
