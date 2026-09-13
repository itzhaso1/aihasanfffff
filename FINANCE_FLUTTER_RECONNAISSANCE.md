# FINANCE FLUTTER RECONNAISSANCE

HASEM is a multi-product Laravel + Flutter monorepo. Finance Web and Shared Payments checkout are complete. This document records what already exists before the Finance Flutter client is built.

## Existing Flutter architecture

| App | Path | Product | API | Platforms |
| --- | --- | --- | --- | --- |
| Hasim Chat | `apps/hasim` | Inbox / CRM / appointments | `/api/mobile/v1` | Android, iOS, Web |
| Hasim Cashier | `apps/hasim_cashier` | POS | `/api/cashier/v1` | Android, iOS, Web, **Windows** |
| Hasim Finance | **new** `apps/hasim_finance` | Finance (independent) | `/api/finance/v1` | Android, iOS, Web, Windows |

**Do not** add Finance screens to Cashier or Chat. POS invoices in Cashier remain POS. Finance invoices remain Finance.

### Reusable infrastructure (copy patterns, not product code)

From `apps/hasim`:

- Riverpod + go_router + Dio
- `flutter_secure_storage` for Sanctum token
- `shared_preferences` for workspace id + API base override
- Arabic-first `gen-l10n` + forced RTL
- Brand color `#06C2A4`, Cairo via Google Fonts
- `AsyncBody` loading/error/empty
- 401 → clear session
- `X-Workspace-Id` header
- Envelope `{ success, data, meta, message }`

From `apps/hasim_cashier`:

- Windows desktop target
- Wide-shell sidebar navigation (Finance will use a Finance sidebar, not POS sections)

Cashier local Drift/SQLite offline POS **must not** be reused for Finance accounting.

## Reusable Laravel infrastructure

- `FinanceApiController::ok/fail` envelope
- `AuthorizesFinanceApi` (Spatie permission **or** `workspace.manage` **or** owner/admin/manager)
- Existing domain services: Invoice, Quote, Payment, Receipt, Statement, Expense, Contract, Export, Dashboard, Checkout, Email, PDF
- Sanctum + `workspace.resolve` + `workspace.member`
- `CustomerService` for party CRUD
- `CustomerBalanceService` as AR source of truth
- Shared `PaymentService` / `BillableCheckoutPort` (Flutter never talks to Stripe)

## Finance API inventory (before this task)

Existing `/api/finance/v1` (e-invoicing/compliance only):

| Method | Path | Role |
| --- | --- | --- |
| GET | `/invoices`, `/invoices/{id}` | e-invoice summary/detail |
| POST | `/invoices/{id}/issue` | issue |
| GET | `/invoices/{id}/xml\|qr\|pdf` | compliance artifacts |
| POST | `/invoices/{id}/cryptographic-stamp` | production stamp gate |
| GET/POST | `/notes…` | credit/debit e-invoice read/issue |
| GET | `/pos-invoices…` | POS e-invoice read (**not** Finance product UI) |

Auth: `GET /api/auth/me` has **no permissions**. Cashier `/api/cashier/v1/auth/me` does. Mobile `/api/mobile/v1/auth/*` is the Chat login surface.

## Missing API endpoints (required for Flutter)

Must be added as thin wrappers over existing services (no second domain):

- Auth/session with permission map + finance feature flag
- Dashboard metrics
- Customers + outstanding balance
- Quotes CRUD + issue/send/accept/reject/convert/pdf
- Invoice create/update draft/cancel/send/remind/checkout/manual payment/reverse
- Payments list
- Receipts list/show/pdf/send
- Statements + PDF/CSV
- Credit/debit create/cancel/pdf (issue already exists)
- Contracts + billing schedule read/lifecycle
- Expenses CRUD + attachment
- Purchases (sales-invoice engine with `type=purchase`) + suppliers
- Reports JSON + CSV
- Dataset CSV exports

## Proposed navigation

```
Finance
├── Dashboard
├── Customers
├── Quotes
├── Invoices
├── Payments
├── Receipts
├── Statements
├── Notes
├── Contracts
├── Expenses
├── Purchases
├── Reports
└── Settings (workspace switch, theme, API host — no secrets)
```

Desktop: persistent sidebar. Mobile: NavigationBar for primary modules + More for the rest.

## Proposed module structure

`apps/hasim_finance/lib/{core,features/*}` mirroring Hasim Chat: `data` repository, `providers` Riverpod, `presentation` screens. Laravel remains source of truth for money, tax, status, permissions.

## Risks

1. Existing Finance v1 is compliance-shaped; client list/detail must not break Phase 10 contract tests (additive fields / new routes only).
2. `/pay/local/{token}` is a placeholder URL; checkout still opens the provider URL and refreshes from Laravel.
3. Merchant eligibility can make checkout `unsupported` — UI must show server message.
4. Owner/admin/manager bypass specific permissions (same as Web). Flutter permission map must match that.
5. Do not load POS invoices into Finance lists.

## Implementation decisions

1. **New app** `apps/hasim_finance`, not a tab inside Chat or Cashier.
2. **Single API prefix** `/api/finance/v1` including login/me so the client does not depend on Chat Mobile API.
3. Login reuses `MobileAuthService` (`device_type=finance`).
4. JSON `{success,data,meta,message}` consistent with existing Finance v1.
5. Flutter never computes authoritative totals; it displays server fields.
6. Checkout: POST Laravel → show URL → copy/open → refresh invoice. Never mark paid locally.
7. POS e-invoice routes stay for compliance clients; Finance Flutter ignores them.
8. Payroll/inventory/AI/Inbox/WhatsApp remain excluded.
