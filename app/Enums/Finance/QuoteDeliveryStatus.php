<?php

namespace App\Enums\Finance;

/**
 * Quote-level delivery concepts kept separate from document status.
 * Per-send history lives on finance_document_deliveries (email sent/failed).
 * Viewed tracking is reserved for a later phase and is not persisted here.
 */
enum QuoteDeliveryStatus: string
{
    case Unsent = 'unsent';
    case Sent = 'sent';
    case Viewed = 'viewed';
}
