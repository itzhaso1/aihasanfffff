# Finance Flutter — intentional Web-only differences

Only genuine architectural or product-boundary differences. Unfinished Finance features are not listed here.

| Web feature | Why it remains Web-only | Architectural / product justification | Other product? | Admin-only? | Desktop-only? | Security? | Already represented in Flutter? |
|---|---|---|---|---|---|---|---|
| Payroll, allowances, deductions, bonuses, salary advances, Finance employees | HR/payroll domain rendered inside the Finance Web shell | Independent payroll product. Copying it would merge products. | Yes — HR/Payroll | Payroll permissions | No | No | No — and must not |
| General ledger journal **create** / chart-of-accounts **editors** | Web accounting hub is read-only | `AccountingController` lists accounts, journals, trial balance, cash flow. There is no Web journal/COA editor to port. Flutter shows the same read hub. A posting API would invent an editor Web does not have and risk a second ledger. | No | n/a | No | Posting integrity if invented | `/accounting` read + `/reports` |
| Product **CMS** (create/edit catalog, variants, website products) | Products product owns the master | Finance Web products page is a **read** of `Product` + sold totals. Flutter matches that read view. | Yes — Products | Products permissions | No | No | `/products` list/detail |
| Inventory receiving / POS stock ops | Inventory operations belong with Products/POS | Finance lists movements and inventory **valuation report**. | Yes — POS/Products | No | No | No | `/inventory`, inventory-valuation report |
| ZATCA certificates, private keys, XML/QR stamping UI, integration secrets | Server-controlled compliance | Flutter must not hold keys or stamp locally. Settings show `zatca_integration_mode` read-only. | No | Finance settings | No | Yes | Read-only mode; `has_qr` on invoices |
| Invoice numbering **sequences** and secret payment-provider credentials | Server-owned configuration | Flutter can set prefix / allow-manual flag / footer / color / logo. Sequences and provider secrets stay on Laravel. | No | Settings | No | Yes | Prefix, allow-manual, logo |
| Pixel-identical Blade layout / Alpine invoice builder | Native Flutter client | Feature parity, not HTML clone. | No | No | Web CSS | No | Sectioned RTL desktop forms |
| Platform `/notifications` inbox | Platform messaging | Finance **alerts** hub is the Finance notification surface. Inbox/WhatsApp/Instagram/Messenger are other products. | Yes — Inbox | No | No | No | `/alerts` |
| Dummy Orders for Finance checkout | Forbidden architecture | Shared billable checkout. GET does not create Payment. POST may create pending checkout. Webhook settles. | Payments | No | No | Settlement integrity | Invoice checkout URL copy/open/refresh |
| Recurring expense **scheduler** | Laravel stores `is_recurring` boolean only | No engine exists on Web or API. Flutter exposes the flag. | No | No | No | No | Recurring flag |
| Web `cashbox` placeholder page | Placeholder, not a product | `ModulePageController::cashbox` is an empty placeholder. Not in the sidebar. | No | No | No | No | Treasury cash accounts |
| Web `modules/sales` placeholder | Unused placeholder | Named sales route uses `SalesController`, which Flutter mirrors. | No | No | No | No | `/sales` |
| Reports index decorative charts (period-comparison widgets) | Blade visualization of the same services | Named ledger reports + dashboard analytics expose the numbers. Chart chrome is Web presentation, not a missing calculation. | No | No | Desktop charts | No | Dashboard analytics + `/reports` |
| Phase 10 `/api/finance/v1/invoices` e-invoice contract | Separate compliance surface | Flutter sales invoices use `/sales-invoices` only. | Finance API versions | No | No | Do not break stamp/XML clients | N/A |
| POS invoices / cashier orders | Other product | Do not list POS invoices as Finance sales. | Yes — POS/Cashier | No | No | No | No |
| Booking | Other product | Independent appointments product. | Yes — Booking | No | No | No | No |
| Authoritative tax / totals / balances / ZATCA math in Flutter | Laravel is the calculator | Flutter displays server Money strings and omits line totals from payloads. | No | No | No | Yes | All money fields |

## Previously listed as Web-only / PARTIAL — now implemented in Flutter

These are **not** Web-only:

- Contract file attachments (upload/list/download/delete) via `ContractService` and `/contracts/{id}/attachments`.
- Company logo upload/replace/remove via `/settings/logo`, reusing Web snapshot-safe delete rules.
- Dashboard product / project / lifecycle / payment-method filters (existing GET `/dashboard` query params).
- Bank statement create / lines / suggest / match / ignore / complete via `BankReconciliationService`.
- Settings tax-rate list/create (`is_default`, `is_active`) and treasury cash/bank create with opening/current balance and linked ledger account. GET `/settings` now returns `tax_rates`, `treasury_accounts`, and `finance_accounts`.

## Quote attachments — not listed as Web-only

Web quote forms do not attach files. The create form’s multipart enctype is leftover from the shared document builder. `QuoteService` never stores uploads. Flutter correctly has no quote-file UI.
