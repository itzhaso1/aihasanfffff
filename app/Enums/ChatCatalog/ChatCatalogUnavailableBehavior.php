<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogUnavailableBehavior: string
{
    case Hide = 'hide';
    case ShowUnavailable = 'show_unavailable';
    case ShowInquiryOnly = 'show_inquiry_only';
    case ShowDisablePurchase = 'show_disable_purchase';
}
