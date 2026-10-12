# SHARED PAYMENTS — FINANCE BILLABLE CHECKOUT

## Current payment architecture

Shared Payments already owned gateway adapters (`local`, `stripe`), `PaymentService`, `payments` / `payment_gateways` / `webhook_events`, HMAC webhook verification, and `PaymentConfirmed`.

POS/commerce path (unchanged in behavior):

`Order` → `PaymentService::createPaymentLink(Order)` → provider link → `POST /api/webhooks/payments/{provider}` → lock Order by `order_number` → mark `payments` + Order paid → `PaymentConfirmed`.

Finance collection path (before this work):

manual `InvoicePaymentService::recordPayment()` → GL + `amount_paid` / `amount_due` + receipt.

Online Finance checkout was explicitly unsupported: `OrderBoundBillableCheckout` refused to create a link so Finance would not invent dummy POS Orders.

## Problem discovered

1. `payments.order_id` was `NOT NULL` FK (`cascadeOnDelete`).
2. `createPaymentLink()` required an `Order`.
3. Webhooks resolved only `Order.order_number`.
4. `PaymentConfirmed` listeners assumed `order_id` (Appointments `where order_id = null` would have been unsafe; notification accessed `$payment->order->order_number`).
5. Finance already had `BillableCheckoutPort` / `InvoiceCheckoutService` as a clean boundary.

## Architecture chosen

Smallest additive shared abstraction:

| Billable | Storage | Provider reference | Settlement |
| --- | --- | --- | --- |
| POS/commerce Order | `order_id` set, `billable_*` null | `order_number` (also stored as `checkout_reference`) | existing Order update |
| Finance invoice | `order_id` null, `billable_type=finance_invoice`, `billable_id` | `fininv:{workspaceId}:{invoiceId}` | `InvoicePaymentService::recordPayment()` |

XOR is enforced in `PaymentService` (never both Order and Finance on one row). No polymorphic morph map, no second gateway, no dummy Orders.

`SharedBillableCheckout` implements `BillableCheckoutPort` and calls `PaymentService::createBillablePaymentLink()`. Finance still does not own provider secrets or webhook verification.

Generating a link stores `status=pending`. Only a verified provider webhook with `status=paid` may post Finance payment.

## Database changes

Migration `database/migrations/2026_09_10_210000_add_billable_checkout_to_payments_table.php`:

- `order_id` nullable, FK `nullOnDelete` (existing Order rows unchanged)
- `billable_type` (nullable string)
- `billable_id` (nullable unsigned bigint)
- `checkout_reference` (nullable string; indexed, not unique — Orders may have more than one pending attempt historically)
- indexes: `(workspace_id, billable_type, billable_id)`, `(workspace_id, checkout_reference)`, `(provider, checkout_reference)`

No rewrite of existing payment rows. No ZATCA tables touched.

## Services changed

- `PaymentService` — Order `createPaymentLink` preserved; added `createBillablePaymentLink`, `findPendingBillablePayment`, webhook resolution by `checkout_reference` then legacy Order number; Finance settlement in the same DB transaction as marking the shared `Payment` paid
- `SharedBillableCheckout` — port implementation
- `InvoiceCheckoutService` — GET `availability()` never creates a link; POST `createCheckout()` does; reuses pending link when amount still matches
- `InvoiceEmailService` — includes existing checkout URL + CTA when a pending link exists (does not generate a link as a side effect of send)
- `MerchantPaymentEligibilityService` — reused via `merchant_invoice` → `merchant_gmv` (same merchant gate as `merchant_order`)
- `AppointmentBillingService::syncAfterPaymentConfirmed` — returns immediately when `order_id` is null

`OrderBoundBillableCheckout` removed (replaced, not left as a second adapter).

## Provider changes

- `LocalPaymentGateway::verifyWebhook` forwards optional `amount` / `currency`
- `StripePaymentGateway::createPaymentLink` uses `metadata.description` when present (Orders still send “Order {number}”); webhook reads `amount_total` / `currency` when present
- Gateway implementations remain outside Finance

## Webhook changes

`PaymentService::processWebhook`:

1. Verify signature (existing HMAC / Stripe)
2. Replay-protect via `webhook_events` (`provider` + `external_event_id`)
3. Resolve `Payment` by `checkout_reference` + provider; else Order by `order_number`
4. Workspace from payment (preferred), else gateway, else order
5. Require `verified` + `status=paid`
6. Finance: match optional amount/currency to stored Payment; require issued, non-cancelled, workspace-scoped invoice; amount ≤ remaining due; then `InvoicePaymentService::recordPayment()` with reference `checkout:{payment.id}`
7. Order: previous paid/confirmed behavior

Return URLs and “open link” clicks never settle.

## Finance integration

- UI: invoice show **الدفع الإلكتروني** — generate / copy / open
- Route: `POST workspace.finance.invoices.checkout` (`payments.manage`; owners/admins/managers still bypass via `FinanceBaseController`)
- Email: invoice email lines + `action_url` when a pending link exists
- Receipt/GL/balance: unchanged Finance engine after `recordPayment()`

## POS compatibility

- `createPaymentLink(Order)` still writes `order_id`
- Also stores `checkout_reference = order_number` so the new webhook resolver finds it
- Legacy rows without `checkout_reference` still settle by order number
- Cashier cash flows still do not use `payments`
- Appointments still create real booking Orders (unchanged); they do not collide with Finance invoice checkout

## Security

- Workspace assert + `authorizeFinance` on generate
- Invoice route binding remains workspace-scoped (cross-workspace 404)
- Webhook signature required (empty local secret still unverified)
- Finance amount/currency checked when the provider reports them
- Cancelled / draft / paid invoices cannot generate or settle
- Over-due amount rejected (uses `InvoiceStateService::PAYMENT_TOLERANCE`)
- No public Finance document URLs added
- Merchant eligibility still required for `merchant_*` contexts (same as Order)

## Idempotency

- Pending Finance checkout reused when amount unchanged
- `webhook_events` unique event id skips duplicates
- Paid shared `Payment` + `recordPayment(reference=checkout:{id})` unique on `(workspace, invoice, reference)`
- GL `invoice_payment` already-posted guard
- Receipt `ensureForPostedPayment` unique `payment_id`

## Tests

`tests/Feature/Feature/Finance/FinanceInvoiceCheckoutTest.php` (14 tests):

1. Generate real link; no Order; invoice stays unpaid
2. Correct billable / workspace / amount / currency / reference stored
3. UI shows generate then copy/open
4. Reuse pending payment
5. Webhook posts Finance payment + GL + receipt once
6. Duplicate webhook idempotent
7. Invalid signature cannot settle
8. Cancelled invoice cannot settle
9. Wrong amount cannot settle
10. Wrong reference cannot settle
11. Workspace isolation
12. Agent without `payments.manage` cannot generate
13. Invoice email includes existing link
14. Manual payment + reverse still work; Order link + webhook still work; draft cannot generate

`FinanceWebCompletionTest` updated so GET show still does not create Orders/Payments.

## Exact commands

```bash
php vendor/bin/phpunit tests/Feature/Feature/Finance/FinanceInvoiceCheckoutTest.php --no-coverage

php vendor/bin/phpunit \
  tests/Feature/Feature/Finance/FinanceInvoiceCheckoutTest.php \
  tests/Feature/Feature/Finance/FinanceWebCompletionTest.php \
  tests/Feature/Feature/Api/OrderPaymentFlowTest.php \
  tests/Unit/Payment/WebhookReplayProtectionTest.php \
  tests/Unit/PaymentWebhookSecurityTest.php \
  tests/Unit/Payment/WebhookVerificationTest.php \
  --no-coverage

php vendor/bin/phpunit \
  tests/Feature/Feature/Finance \
  tests/Unit/Finance \
  tests/Unit/Payment \
  tests/Unit/PaymentWebhookSecurityTest.php \
  tests/Unit/EInvoicing \
  tests/Feature/Feature/Workspace \
  tests/Feature/Feature/Tenancy/WorkspaceIsolationTest.php \
  tests/Feature/Feature/Api/OrderPaymentFlowTest.php \
  tests/Feature/Feature/Merchant/MerchantVerificationTest.php \
  tests/Feature/Feature/Appointments \
  tests/Unit/Appointments \
  --no-coverage

php vendor/bin/phpunit --no-coverage
```

## Exact final test counts

PHPUnit 12.5.33, SQLite `:memory:`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`.

| Suite | Tests | Passed | Skipped | Risky | Warnings |
| --- | ---: | ---: | ---: | ---: | ---: |
| Focused checkout + Order webhook + completion | 35 | 35 | 0 | 0 | 0 |
| Finance + Unit Finance/Payment + E-Invoicing + Workspace/Tenancy + Appointments + Order/Merchant | 454 | 452 | 2 | 1 | 1 |
| **Full PHPUnit** | **711** | **707** | **4** | **1** | **1** |

Assertions (full suite): 5307.

No tests deleted, skipped, or weakened.

## Skipped / risky / warning tests

**Skipped (4, pre-existing):**

- Phase 7 ICV concurrency (2) — needs mysql/pgsql + pcntl
- `CentralEmailServiceTest` (2) — array mailer in this environment

**Risky (1, pre-existing):** `Phase6UblXmlFoundationTest::test_xml_generation_does_not_query_live_business_tables`

**Warning (1, pre-existing):** `X509CertificateParser.php:21` `openssl_x509_read()`

## Remaining limitations

- `/pay/local/{token}` is still a placeholder URL (pre-existing for Order checkout too). Local confirmation is via signed webhook, which is how Order tests already worked.
- Stripe still creates Payment Links and treats `checkout.session.completed` as paid (pre-existing adapter shape). Finance now sends `fininv:{workspace}:{invoice}` as `metadata.reference`.
- Online checkout requires the existing merchant eligibility gate (plan feature + verification approved + provider active), same as Order `merchant_order`.
- Recurring/partial online top-ups create a new pending link if `amount_due` changes; they do not auto-issue invoices.

## Shared-platform dependencies

- Merchant verification / HyperPay onboarding (eligibility only)
- Provider credentials (`STRIPE_*`, `LOCAL_PAYMENT_WEBHOOK_SECRET`)
- Central email transport for invoice messages that include the link

## Intentionally not implemented

- Dummy POS Orders
- Finance-owned gateway
- Marking invoices paid from link generation or return URL
- Flutter / Booking / Inbox / AI / ZATCA rewrites
- Public customer portal

## Final verdict

**SHARED PAYMENTS FINANCE CHECKOUT: COMPLETE**
