# Merchant Payments

## Three money contexts (never mix)

1. **Platform subscription** — Workspace pays SaaS plan → `payment_context=platform_subscription`, `money_bucket=platform_revenue`
2. **Platform commerce** — Domain / add-on purchase → `platform_commerce`
3. **Merchant customer payment** — End customer pays merchant order → `merchant_order`, `money_bucket=merchant_gmv`

Merchant GMV is **not** Platform Revenue.

## Eligibility to accept customer payments

All must be true:

1. Plan feature `payments` **or** `payment_gateway` enabled
2. `merchant_profiles.verification_status = approved`
3. `merchant_profiles.provider_onboarding_status = active`

Service: `MerchantPaymentEligibilityService`

## HyperPay status (honest)

- Subscription checkout still uses a **frontend placeholder** string `hyperpay` — not a live charge API.
- Merchant settlement provider: `HyperPayMerchantSettlementProvider`
  - Does **not** invent marketplace/split success
  - Without credentials + `HYPERPAY_MERCHANT_ONBOARDING_ENABLED`, status stays `pending`
  - `active` only with staging sandbox auto-approve env (documented as non-production)

Real HyperPay marketplace/split requires provider contract, credentials, and official onboarding APIs — **not implemented as live**.
