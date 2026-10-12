<?php

namespace App\Enums\Commerce;

enum CommerceSellerType: string
{
    /** Default storefront seller owned by the workspace (legacy merchant model). */
    case WorkspaceMerchant = 'workspace_merchant';

    /** Participating seller in a marketplace operated by the workspace. */
    case MarketplaceSeller = 'marketplace_seller';
}
