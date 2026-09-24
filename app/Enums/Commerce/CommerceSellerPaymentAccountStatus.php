<?php

namespace App\Enums\Commerce;

enum CommerceSellerPaymentAccountStatus: string
{
    case NotConfigured = 'not_configured';
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Failed = 'failed';
}
