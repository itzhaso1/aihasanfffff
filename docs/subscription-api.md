# Subscription API

Server-side contract for future Flutter clients. Laravel remains the source of truth for plans, prices, entitlements, checkout, and lifecycle.

Base prefix: `/api`  
Auth: Laravel Sanctum bearer token  
Workspace: `X-Workspace-Id` header and/or token `workspace_id`. Membership is verified server-side.

Do **not** send `workspace_id` in the JSON body to select a tenant. Controllers never read it. Existing workspace middleware may see it only as a fallback after the header/token, and only if the user is already an active member of that workspace.

Generic `/api` JSON shape (not the mobile `{ success, data, meta }` envelope):

```json
{ "data": {}, "message": "…" }
```

Errors follow Laravel JSON: `{ "message": "…" }` plus `{ "errors": {…} }` on validation failures (401 / 403 / 404 / 422 / 405).

## Authorization

| Action | Who |
| --- | --- |
| Read plans / current subscription | Authenticated workspace member (`SubscriptionPolicy::viewAny` / `view`) |
| Create checkout, change plan, read checkout, cancel | Owner or admin (`SubscriptionPolicy::manageBilling`) |
| Direct create / activate | Never. `POST /api/subscriptions` returns **405** |

Cross-workspace subscription or checkout IDs resolve through `WorkspaceScopedModel` and return **404**.

## Client never controls

The client may send **only** `plan_id` on checkout / change-plan.

The server ignores and must never persist from the client:

- `amount` / `price`
- `currency`
- `trial_days`
- `features` / `limits` / `permissions` / `overage_rules`
- `payment_provider`
- `payment_status` / `subscription_status` / `checkout_status`
- `verified` / `paid` / activation flags
- `workspace_id` / `user_id` as authoritative fields

## Endpoints

### GET `/api/subscriptions/plans`

Auth: Sanctum + workspace member  
Authorization: read

Returns official, active, public catalog plans. Inactive, internal, and legacy plans are omitted. No pagination (fixed catalog).

```json
{
  "data": [
    {
      "id": 1,
      "code": "starter",
      "name": "Starter",
      "display_name_ar": null,
      "description": null,
      "tier": "starter",
      "price": "49.00",
      "currency": "SAR",
      "billing_period": "monthly",
      "trial_days": 7,
      "features": ["dashboard"],
      "limits": { "users": 3 }
    }
  ]
}
```

### GET `/api/subscriptions/current`

Auth: Sanctum + workspace member  
Authorization: read

`subscription` is the entitled subscription (`active` / `trialing` / `past_due`) or, if none, the latest row (paused / cancelled / expired) so the client can render state. `null` when the workspace has never subscribed.

`entitlements` is a sanitized FeatureAccess snapshot (features, limits, meters, subscription_status). Overrides and comparison rows are not exposed.

```json
{
  "data": {
    "subscription": {
      "id": 10,
      "status": "active",
      "status_label": "نشط",
      "plan": { "id": 1, "code": "starter" },
      "starts_at": "2026-09-12T00:00:00+00:00",
      "trial_ends_at": null,
      "trial_days_remaining": null,
      "current_period_start": "2026-09-12T00:00:00+00:00",
      "current_period_end": "2026-10-12T00:00:00+00:00",
      "grace_ends_at": null,
      "paused_at": null,
      "cancelled_at": null,
      "ends_at": null
    },
    "entitlements": {
      "features": ["dashboard"],
      "limits": {},
      "meters": {},
      "subscription_status": "active"
    }
  }
}
```

### GET `/api/subscriptions`

Convenience read: `{ data: { current, plans } }`. `current` is only the entitled subscription (or `null`). Same auth as plans.

### POST `/api/subscriptions/checkout`

Auth: Sanctum + workspace member  
Authorization: `manageBilling`  
Throttle: `subscription-billing`

Request:

```json
{ "plan_id": 2 }
```

Server resolves user, workspace, plan, price, currency, billing period, expiration, merchant reference, and provider. Checkout starts as:

- `checkout_status`: `awaiting_payment`
- `payment_status`: `pending`
- `subscription_status`: `pending_activation`

Creating checkout **never** activates a subscription.

Same-plan repurchase → **422**. Inactive / internal / legacy plan → **404**.

### GET `/api/subscriptions/checkout/{checkout}`

Auth: Sanctum + workspace member  
Authorization: `manageBilling`

Read-only. Query/body payment fields are ignored. `is_expired` is computed from stored status or `expires_at`. This endpoint does not create a replacement checkout; start a new `POST /checkout` after expiry.

```json
{
  "data": {
    "id": 44,
    "plan": { "id": 2, "code": "pro" },
    "amount": "149.00",
    "currency": "SAR",
    "checkout_status": "awaiting_payment",
    "payment_status": "pending",
    "subscription_status": "pending_activation",
    "payment_provider": "hyperpay",
    "expires_at": "2026-09-13T00:00:00+00:00",
    "is_expired": false,
    "is_verified_paid": false,
    "activated_subscription_id": null,
    "reference": "subchk_…"
  }
}
```

### POST `/api/subscriptions/change-plan`

Auth: Sanctum + workspace member  
Authorization: `manageBilling`  
Throttle: `subscription-billing`

Request is only `{ "plan_id": … }`. The server sets `change_kind` to `upgrade`, `downgrade`, or `choose` from plan prices. Client `upgrade` / `downgrade` fields are ignored.

Paid changes create a checkout (`awaiting_payment`). They do **not** call `SubscriptionService::upgrade()` / `downgrade()` (those activate immediately and are not an HTTP API).

Same current plan → **422**.

### POST `/api/subscriptions/cancel`

Auth: Sanctum + workspace member  
Authorization: `manageBilling`  
Throttle: `subscription-billing`

Cancels the authenticated workspace subscription (current, or latest if that is the visible row). No client `subscription_id` / `workspace_id` / `status`.

| Case | Result |
| --- | --- |
| `trialing` / `active` / `past_due` / `paused` | Cancelled |
| Already `cancelled` | **200** idempotent |
| `expired` or other closed state | **422** |
| No subscription | **422** |

Cancel does not activate or change plan.

### POST `/api/subscriptions`

Auth: Sanctum + `manageBilling`  
Always **405**. Direct activation is closed (Phase 1).

There is no frontend payment-confirmation API. Browser return and “I paid” claims do not activate.

## Checkout / payment lifecycle

```
create checkout → awaiting_payment
        ↓
trusted verification only:
  - provider_webhook (verified)
  - local_billing_provider (local/testing only)
        ↓
activated
```

Expired checkouts cannot activate. Renewal without a verified payment does not extend the period (Phase 2 commands).

## Subscription statuses

`trialing` · `active` · `past_due` · `paused` · `cancelled` · `expired`

Entitlements (FeatureAccess) remain available only for `active` / `trialing` / `past_due`. This API does not change those rules.

## HyperPay

HyperPay is a **fail-closed stub**. There is no official charge / 3DS / webhook contract in this repository.

Until a real account and official API exist:

- Checkout can be created and polled
- `payment_provider` is typically `hyperpay`
- Remote session creation is blocked (`blocked_missing_official_contract` in server metadata — not part of the public resource)
- Webhooks are rejected (`hyperpay_verification_not_configured`)
- No client or webhook claim can mark payment as verified
- Local billing is not a production fallback

Do not document invented HyperPay endpoints.

See `docs/hyperpay.md`.

## Flutter notes

- Predictable `{ data }` objects; do not persist Blade-only labels as business state (`status_label` is localized display).
- Treat `is_verified_paid` / `activated_subscription_id` as the only activation signals.
- Poll `GET /checkout/{id}` after the user returns from a future payment page; never POST a paid flag.
- This surface is `/api/subscriptions/*`, not `/api/mobile/v1`.
