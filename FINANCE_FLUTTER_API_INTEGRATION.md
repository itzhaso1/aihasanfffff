# FINANCE FLUTTER API INTEGRATION

Finance Flutter (`apps/hasim_finance`) talks only to Laravel `/api/finance/v1`. Laravel remains the source of truth for money, tax, status, permissions, workspace access, numbering, GL, and ZATCA.

Authentication: Sanctum bearer token (`Authorization: Bearer`) plus `X-Workspace-Id`. Envelope: `{ success, data, meta, message }` on success; `{ success, message, code, errors? }` on failure.

Token storage key: `hasim_finance_access_token` (secure storage). Workspace id: `hasim_finance_workspace_id` (preferences). Google Sign-In setup: `apps/hasim_finance/GOOGLE_SIGNIN.md`. Architecture: `FINANCE_FLUTTER_AUTH_IMPLEMENTATION_REPORT.md`.

## Auth (no workspace middleware)

| Method | URL | Auth | Permissions | Request | Response | Errors | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- |
| POST | `/api/finance/v1/auth/login` | none | — | `email` **or** `phone` **or** `email_or_phone`, `password`, optional `workspace_id`, `device_name`, `device_type=finance` | `token`, `user`, `workspace`, `workspaces`, `permissions` (flat dotted keys), `finance_enabled` | 401 unauthorized, 422 validation_failed, 404 no workspace | Reuses `MobileAuthService::loginWithPassword` |
| POST | `/api/finance/v1/auth/google` | none | — | `access_token` (Google access token already verified only after Laravel Socialite), optional `workspace_id`, `device_name`, `device_type=finance` | same session envelope as password login | 401 invalid Google credential (generic message), 422, 404 | Reuses `MobileAuthService::loginWithSocial` → `SocialAuthService`. Do not send client secrets. |
| POST | `/api/finance/v1/auth/social` | none | — | `provider=google`, `access_token`, optional workspace/device | same | 401, 422 (`provider` must be `google`) | Thin alias; Finance does not accept Facebook. |
| POST | `/api/finance/v1/auth/google/start` | none | — | — | `{ ticket, auth_url, expires_in }` | 422 if Laravel Google env missing | Shared `CashierGoogleBrowserLogin` with `product=finance` |
| GET | `/api/finance/v1/auth/google/status` | none | — | `ticket` (uuid) | `{ status, access_token?, error? }` (`pending` / `ready` / `failed`; expired → 404) | 404 expired, 422 | Browser OAuth for Windows / plugin fallback. Token is Google access token, not Sanctum. Client then POSTs `/auth/google`. |
| POST | `/api/finance/v1/auth/forgot-password` | none | — | `email` | message | 422 | Existing Laravel password broker |
| POST | `/api/finance/v1/auth/reset-password` | none | — | `token`, `email`, `password`, `password_confirmation` | message | 422 | |
| POST | `/api/finance/v1/auth/logout` | Sanctum | — | — | message | 401 | Revokes current PAT and web session |
| GET | `/api/finance/v1/auth/me` | Sanctum | — | — | session payload without a new token | 401 | |
| GET | `/api/finance/v1/workspaces` | Sanctum | — | — | `{ workspaces: [...] }` | 401 | |

## Workspace-scoped client surface

All routes below require `auth:sanctum`, `workspace.resolve`, `workspace.member`. Cross-workspace reads return **404** `not_found` (global scopes). Missing permission returns **403** `forbidden`. Domain `RuntimeException` returns **422** `validation_failed`.

Owner / admin / manager membership **or** Spatie `workspace.manage` **or** the named permission may pass. Agents do **not** inherit cashier-style elevation.

### Session / bootstrap

| Method | URL | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/workspaces/current` | member | current workspace + permission map |
| POST | `/workspaces/switch` | member | body `{ workspace_id }`; updates Sanctum token workspace |
| GET | `/bootstrap` | `finance.view` | permissions, safe company settings, catalogs (customers/products/tax/suppliers/categories/treasury). No secrets. |
| GET | `/dashboard` | `finance.view` | cards from `DashboardService` + posted payments this month as `paid_this_period`. Extra cards: purchases, VAT, cash/bank, net profit, active contracts. `recent_expenses` and `overdue_invoices` included. Payroll KPIs are omitted on purpose. |
| GET | `/search?q=` | `finance.view` | customers, sales invoices, quotes, receipts, payments, expenses, suppliers, contracts, purchases. No web URLs. |

### Customers

| Method | URL | Permission |
| --- | --- | --- |
| GET | `/customers` | `finance.view` |
| POST | `/customers` | `customers.manage` |
| GET | `/customers/{id}` | `finance.view` |
| PUT | `/customers/{id}` | `customers.manage` or invoice create / finance.manage |

`outstanding_balance` is `CustomerBalanceService` (SUM issued sales `amount_due`). The column `customers.balance` is **not** returned.

### Quotes

| Method | URL | Permission |
| --- | --- | --- |
| GET/POST | `/quotes` | view / create |
| GET/PUT/DELETE | `/quotes/{id}` | view / edit / delete |
| POST | `/quotes/{id}/issue\|cancel\|accept\|reject\|convert\|send` | matching `quotes.*` |
| GET | `/quotes/{id}/pdf` | `quotes.view` |

Create/update accept `items[]` (client totals stripped). Convert creates a **draft** sales invoice via `QuoteService` / `InvoiceService`. Send requires `email`.

### Sales invoices (Finance product)

| Method | URL | Permission |
| --- | --- | --- |
| GET/POST | `/sales-invoices` | view / create |
| GET/PUT/DELETE | `/sales-invoices/{id}` | view / edit / delete |
| POST | `/sales-invoices/{id}/issue\|cancel\|send\|remind` | matching `invoices.*` |
| GET | `/sales-invoices/{id}/checkout` | `payments.view` | **availability only** — never creates a Payment |
| POST | `/sales-invoices/{id}/checkout` | `payments.manage` | `InvoiceCheckoutService` → `BillableCheckoutPort`. Returns `{ checkout, invoice }`. Invoice stays unpaid. |
| POST | `/sales-invoices/{id}/payments` | `payments.manage` | manual payment → receipt + GL |
| POST | `/sales-invoices/{id}/payments/{payment}/reverse` | `invoices.reverse_payment` | voids receipt |
| GET | `/sales-invoices/{id}/pdf` | `invoices.view` |

Do **not** use `/invoices` for this client. That prefix is the Phase 10 e-invoice contract.

### Payments / receipts / statements

| Method | URL | Permission |
| --- | --- | --- |
| GET | `/payments`, `/payments/{id}` | `payments.view` |
| POST | `/payments/{id}/reverse` | `invoices.reverse_payment` |
| GET | `/receipts`, `/receipts/{id}`, `/receipts/{id}/pdf` | `receipts.view` |
| POST | `/receipts/{id}/send` | `receipts.send` |
| GET | `/statements?customer_id&from&to` | `invoices.view` |
| GET | `/statements?...&format=csv\|pdf` | same | binary download |

### Credit / debit notes (Finance product)

| Method | URL | Permission |
| --- | --- | --- |
| GET/POST | `/credit-notes` | `invoices.view` / `invoices.credit` |
| GET | `/credit-notes/{id}` | `invoices.view` |
| POST | `/credit-notes/{id}/issue\|cancel` | credit / `invoices.cancel` |
| GET | `/credit-notes/{id}/pdf` | `invoices.view` |

`/notes` remains the compliance e-invoice surface.

### Contracts / expenses / purchases / reports / settings

| Method | URL | Permission |
| --- | --- | --- |
| CRUD-ish | `/contracts` + activate/close/cancel/pdf | `contracts.view` / `contracts.manage` |
| POST | `/contracts/{id}/billing-schedules/{schedule}/generate` | `invoices.create` | **draft** invoice only |
| GET/POST/PUT/DELETE | `/expenses` | `expenses.*` |
| GET | `/expenses/{id}/attachment` | `expenses.view` |
| GET/POST/PUT | `/purchases` | `purchases.view` / `purchases.manage` |
| GET | `/purchases/aging` | `purchases.view` |
| GET/POST/PUT | `/suppliers` | view / manage |
| GET | `/reports/{report}` | `reports.view` | keys: profit-loss, trial-balance, cash-flow, balance-sheet, general-ledger, ar-aging, ap-aging, **inventory-valuation**. Query `from`/`to`/`account_id`. `format=csv` for CSV |
| GET | `/exports/{dataset}` | matching view | invoices, payments, customers, expenses, quotes |
| GET/PUT | `/settings` | `finance.settings` | company profile only. No ZATCA keys, sequences, payment secrets |

## Existing e-invoice routes (unchanged)

`GET/POST /invoices…`, `/notes…`, `/pos-invoices…` remain for compliance clients. Finance Flutter does not list POS invoices as sales invoices.

## Idempotency

Checkout POST may send `Idempotency-Key`. Shared Payments still owns provider uniqueness. Generating a checkout URL is **not** payment confirmation.

## Flutter client mapping

- Login identifier field: `email_or_phone` (Laravel also accepts `email` / `phone`).
- Permission map keys are dotted strings (`invoices.view`), not nested JSON objects.
- After every mutation the client re-GETs the resource. Totals on screen always come from the last server payload.

## Additive presenter fields (data-parity pass)

Invoice summary now includes `discount`, `taxable_amount`, `amount_credited`, `amount_debited`. Invoice detail adds snapshots, `project_id`, `zatca.has_qr`. Quote summary includes `discount`/`taxable_amount`. Payments include treasury + notes + `reversed_at`. Expenses include supplier/method/treasury/`is_recurring`. Contracts include `terms`, `items`, schedule `amount`/`next_run_on`, and show-level `generated_invoices`/`billing_summary`. Statements include period totals + line `invoice_id`. Search adds expenses/suppliers/contracts/purchases.

Flutter web PDF/CSV uses a blob download (`download_web.dart`). Native still writes a temp file and opens/shares it.

See `FINANCE_FLUTTER_WEB_DATA_PARITY_AUDIT.md`.

