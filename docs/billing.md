# Billing

## Three money contexts (never mix)

| Context | Who pays | `payment_context` | `money_bucket` | Is Platform Revenue? |
|---------|----------|-------------------|----------------|----------------------|
| Platform subscription | Workspace → SaaS | `platform_subscription` | `platform_revenue` | Yes |
| Domain / add-on commerce | Workspace → platform product | `platform_commerce` | `platform_commerce` | Platform commerce (not subscription MRR) |
| Merchant customer payment | End customer → merchant | `merchant_order` | `merchant_gmv` | **No** (GMV only) |

Merchant GMV must never be reported as Platform Revenue.

## Platform subscription provider

`App\Providers\AppServiceProvider` binds:

`SubscriptionBillingProviderInterface` → `LocalSubscriptionBillingProvider`

That means:

- Checkout sessions can be created and confirmed **locally**.
- There is **no live Stripe Billing / HyperPay charge API** for platform subscriptions in this binding.
- UI may show a HyperPay label as a **placeholder** — confirmation still activates via local provider.

## Merchant customer payments

- Use `PaymentService` + payment gateway manager (`local` / optional `stripe`).
- Merchant contexts call `MerchantPaymentEligibilityService` before creating a payment link.
- Eligibility requires: plan feature `payments`/`payment_gateway` **AND** verification `approved` **AND** provider onboarding `active`.

## HyperPay marketplace / split

See `docs/hyperpay.md`. Live split settlement is **not** implemented. Do not invent provider APIs.

## What to do for production platform billing

1. Implement a real provider against `SubscriptionBillingProviderInterface`.
2. Bind it behind env (`BILLING_PROVIDER=…`).
3. Configure webhook secrets.
4. Keep `LocalSubscriptionBillingProvider` for CI/dev.

## Status label

**Placeholder / local for paid subscription gateways.** Do not advertise “card billing live” until a non-local provider is bound and webhooks verified.
