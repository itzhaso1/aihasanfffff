<?php

namespace App\Enums;

enum ConversationCustomerPriority: string
{
    case Important = 'important';
    case Normal = 'normal';
    case NotImportant = 'not_important';
}
