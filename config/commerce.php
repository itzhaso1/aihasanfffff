<?php

return [
    /*
    | Default operating mode when no commerce_workspace_profiles row exists.
    | Does not alter Finance/POS/Chat behavior in the foundation phase.
    */
    'default_operating_mode' => env('COMMERCE_DEFAULT_OPERATING_MODE', 'independent_store'),

    'default_payment_provider' => env('COMMERCE_DEFAULT_PAYMENT_PROVIDER', 'hyperpay'),

    /*
    | Chat-owned VAT percentage used when a workspace has no commerce tax setting.
    | Keep this at 15.00 unless product explicitly changes the Chat fallback.
    */
    'default_tax_rate' => env('COMMERCE_DEFAULT_TAX_RATE', '15.00'),
];
