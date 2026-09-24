<?php

namespace App\Enums\Commerce;

/**
 * HyperPay settlement/distribution lifecycle — reconciliation only, not a wallet balance.
 */
enum CommerceSettlementStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Settled = 'settled';
    case Failed = 'failed';
}
