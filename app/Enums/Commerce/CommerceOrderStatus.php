<?php

namespace App\Enums\Commerce;

/**
 * Commerce-only lifecycle state. The legacy orders.status enum remains
 * unchanged for POS and historical integrations.
 */
enum CommerceOrderStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case Cancelled = 'cancelled';
}
