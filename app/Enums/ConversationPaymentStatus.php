<?php

namespace App\Enums;

enum ConversationPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
}
