# HyperPay

## What exists today

| Area | Status |
|------|--------|
| Subscription checkout label `hyperpay` | UI label only. Checkout stays `awaiting_payment` |
| Subscription charge / COPYandPAY / 3DS | **Not implemented** — official API contract is not in this repository |
| Subscription webhook verification | Fail-closed (`hyperpay_verification_not_configured`) |
| Frontend / browser return | Never activates a subscription |
| Order payment gateways | `local` + `stripe` only in `PaymentGatewayManager` |
| Merchant marketplace / split onboarding | Abstraction + pending provider (`HyperPayMerchantSettlementProvider`) — **not live** |

## Phase 4 (subscription billing)

HASEM does **not** invent HyperPay endpoints, signatures, status codes, or 3DS flow.

Until an official HyperPay charge + webhook specification is provided:

- `HyperPaySubscriptionBillingProvider::prepareRemoteCheckout()` refuses remote session creation
- `verifySubscriptionWebhook()` rejects every payload
- Credentials (`HYPERPAY_ENTITY_ID`, `HYPERPAY_ACCESS_TOKEN`) are stored server-side only and are **not** treated as proof that subscription charging works
- Local provider is never a production fallback

## Required for production merchant settlement

1. HyperPay account product that supports marketplace / split / beneficiary settlement (confirm contract name with provider)
2. Entity ID + access token in env
3. `HYPERPAY_MERCHANT_ONBOARDING_ENABLED=true`
4. Real onboarding API integration (not invented here)
5. Webhook verification for merchant charges
6. Never route merchant customer funds through Platform subscription account

## Required for production subscription charges

Official HyperPay documentation for:

1. Authentication
2. Checkout / payment session creation
3. Amount and currency fields
4. 3DS / redirect / callback
5. Server-side payment status inquiry
6. Webhook authentication and payload
7. Success / pending / failed / cancelled status values

## Staging-only sandbox

`HYPERPAY_MERCHANT_SANDBOX_AUTO_APPROVE=true` may mark provider onboarding `active` for tests. **Do not enable in production.**

Client subscription HTTP API (plans, current, checkout, change-plan, cancel): `docs/subscription-api.md`. That surface does not add HyperPay charge or webhook endpoints.
