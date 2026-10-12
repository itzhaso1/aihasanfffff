<?php

return [
    /*
    | Server-controlled checkout provider. Never accept this from the client.
    | "local" is rejected outside local/testing by SubscriptionBillingGuard.
    */
    'checkout_provider' => env('SUBSCRIPTION_CHECKOUT_PROVIDER', 'hyperpay'),

    /*
    | Catalog default for new official plans. Runtime charge amount/currency
    | always come from the plans row copied onto the checkout/attempt.
    */
    'currency' => env('PLAN_CURRENCY', 'SAR'),

    'checkout_ttl_hours' => (int) env('SUBSCRIPTION_CHECKOUT_TTL_HOURS', 12),
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 3),
    'renewal_lead_days' => (int) env('SUBSCRIPTION_RENEWAL_LEAD_DAYS', 3),

    /*
    | Local/testing webhook HMAC only. Not a HyperPay signature.
    */
    'webhook_secret' => env('SUBSCRIPTION_WEBHOOK_SECRET'),
    'webhook_tolerance_seconds' => (int) env('SUBSCRIPTION_WEBHOOK_TOLERANCE_SECONDS', 300),
];
