# FINANCE / BILLING — FINAL IMPLEMENTATION AUDIT

**Product:** HASEM Finance (Laravel source of truth + Flutter client `apps/hasim_finance`)  
**Type:** Second audit after implementation. Compares the forensic inventory with the implemented state.  
**Source of truth used:** `FINANCE_BILLING_COMPLETE_FORENSIC_AUDIT.md`  
**Implementation branch:** `cursor/finance-billing-parity-implementation-9bf7`  
**Date:** 10 September 2026  

Status vocabulary (exactly one per feature):

| Status | Meaning |
|--------|---------|
| COMPLETE | Implemented and wired through Laravel → API → Flutter (or intentionally backend-only when that is the product surface) |
| PARTIAL | Exists but still missing a field, action, filter, or client |
| MISSING | Not found for this domain |
| BROKEN | Code contradicts itself or cannot complete the claimed job |
| WEB-ONLY | Exists in Laravel Web; not equivalently exposed to Flutter |
| BACKEND-ONLY | Server implementation with no product UI, or must stay server-side |
| SECURITY-RESTRICTED | Must remain backend-only (secrets, keys, webhook verification) |
| NOT-FINANCE | Adjacent product (POS, Booking, Inbox, platform HR) |

Do not use “mostly complete”.

---

## 1. How to read this report

The forensic audit listed operational billing as complete on Laravel and thinner on Flutter. This pass implemented the genuine gaps. Verification used Laravel → API → (where applicable) Flutter tests. Flutter `flutter analyze` and `flutter test` were executed. Laravel Finance feature tests were executed.

Invariants preserved:

- `CustomerBalanceService::outstanding` remains AR source of truth. `customers.balance` is a cache.
- Checkout URL never means paid. Only a verified provider webhook can settle online checkout.
- Quote conversion creates a DRAFT invoice and stays idempotent (second convert HTTP 200, same `converted_invoice_id`).
- Flutter never authoritatively calculates tax/totals. `HandlesFinanceClient::documentItemsFromRequest` unsets client `total` / `tax_amount` / `taxable_amount` / `subtotal`.
- ZATCA secrets, private keys, certificates, stamp private material, and webhook secrets never leave the backend.
- FATOORA clearance/reporting was **not** implemented. The repository still has no live FATOORA client.
- Owner/admin/manager elevation was **not** tightened. Cashiers are not elevated. Tightening Spatie-only would lock workspace owners who operate Finance without every permission row.

---

## 2. Completeness scores — BEFORE vs AFTER

| Surface | BEFORE (forensic) | AFTER | Status |
|---------|------------------:|------:|--------|
| Laravel Backend | 86 | 90 | COMPLETE for core AR; ZATCA Phase 2 still MISSING by product choice |
| Laravel Web | 82 | 88 | COMPLETE core; ZATCA copy is truthful foundation |
| Finance API | 88 | 93 | COMPLETE billed operations; e-invoice XML/QR now on sales client |
| Flutter Finance | 74 | 90 | COMPLETE billed operations that the API supports |
| Web → API parity | 90 | 94 | COMPLETE billed operations |
| API → Flutter parity | 76 | 92 | COMPLETE billed operations |
| Feature parity | 78 | 91 | See §4 |
| Field parity | 80 | 93 | See invoice/quote matrices |
| Invoice parity | 82 | 94 | Inbox, fields, artifacts, journals |
| Payment parity | 85 | 93 | Filters + purchase payments |
| Quote parity | 84 | 93 | Attachments COMPLETE |
| ZATCA | 45 | 62 | COMPLETE foundation exposure; MISSING FATOORA |
| PDF | 88 | 88 | COMPLETE |
| Email | 85 | 85 | COMPLETE |
| Checkout | 80 | 80 | COMPLETE settle; merchant KYC WEB-ONLY |
| Reports | 80 | 88 | COMPLETE server-authoritative viewer |
| Security | 78 | 82 | Workspace COMPLETE; secrets SECURITY-RESTRICTED |
| Permissions | 72 | 78 | Keys + controller; elevation documented, not tightened |
| Testing | 70 | 86 | Regression tests added and executed |

---

## 3. Phase results

### Phase 1 — P0 correctness — COMPLETE

| Finding | BEFORE | AFTER |
|---------|--------|-------|
| Web ZATCA copy claimed QR/XML are never generated | BROKEN | COMPLETE — Web says foundation XML/QR are generated on issue; FATOORA remains غير مهيأة |
| `has_qr` from placeholder `invoice.zatca_qr_code` | BROKEN | COMPLETE — `EInvoiceArtifactService` uses snapshots + `e_invoice_documents` |
| Dashboard sales included drafts | BROKEN | COMPLETE — `DashboardService` uses `whereIssued()`; expenses exclude draft/cancelled |
| CustomerBalanceService vs `customers.balance` | COMPLETE (invariant) | COMPLETE — unchanged |
| Checkout URL ≠ paid | COMPLETE (invariant) | COMPLETE — unchanged; Web/API still assert unpaid after checkout create |
| Quote convert → draft + idempotent | COMPLETE (invariant) | COMPLETE — regression: draft quote convert is 422; second accepted convert is 200 |

### Phase 2 — Flutter invoice inbox — COMPLETE

| Filter | BEFORE | AFTER |
|--------|--------|-------|
| search | COMPLETE | COMPLETE |
| lifecycle chips | PARTIAL | COMPLETE |
| invoice status | MISSING on Flutter | COMPLETE |
| payment status | MISSING on Flutter | COMPLETE |
| customer | MISSING on Flutter | COMPLETE |
| date from/to | MISSING on Flutter | COMPLETE |
| currency | MISSING on Flutter | COMPLETE |
| project / contract / payment method | MISSING on Flutter | COMPLETE |
| sorting | WEB-ONLY | COMPLETE — API `sort`/`direction` + Flutter |
| pagination | COMPLETE | COMPLETE |
| pipeline / totals meta | WEB-ONLY | COMPLETE — `InvoiceInboxService` meta on sales + purchases |

Filters call existing `InvoiceInboxService` request filters. They are not faked.

### Phase 3 — Invoice data parity — COMPLETE

| Field | BEFORE | AFTER |
|-------|--------|-------|
| `issued_at` | PARTIAL (Web only) | COMPLETE |
| `supply_date` | PARTIAL | COMPLETE |
| `tax_breakdown` | PARTIAL (DB, omitted presenter) | COMPLETE presenter + Flutter summary |
| contract/project names | PARTIAL ids | COMPLETE `contract_number`/`title`, `project_name` |
| line `exemption_code` | PARTIAL (API item, Flutter payload omitted) | COMPLETE — LineDraft `toPayload` + form field |

Trace: model column → InvoiceService/tax engine → `FinanceClientPresenter` → `/sales-invoices/{id}` → `InvoiceRecord` → invoice detail UI.

### Phase 4 — Real ZATCA artifact exposure — COMPLETE (foundation)

| Artifact | BEFORE | AFTER |
|----------|--------|-------|
| XML download | API-ONLY (`/invoices/{id}/xml`) | COMPLETE Web + `/sales-invoices/{id}/xml` + Flutter |
| QR payload | API-ONLY | COMPLETE Web `invoices.qr` + API + Flutter view |
| Availability flags | placeholder `has_qr` | COMPLETE `xml_available` / `qr_available` from e-invoice tables |
| Clearance / reporting / production stamp | MISSING | MISSING — flags stay `false`; UI says foundation only |
| Private keys / CSID / stamp material | SECURITY-RESTRICTED | SECURITY-RESTRICTED — not exposed |

XML GET can return 409 `ComplianceUnavailableException` when seller/buyer snapshot is incomplete. That is correct fail-closed behavior, not a fake FATOORA path.

### Phase 5 — Quote attachments — COMPLETE

End-to-end: migration `finance_quote_attachments` → `FinanceQuoteAttachment` → `QuoteService` SecureUpload under `workspaces/{id}/finance/quotes/{quoteId}` → Web store/download/destroy → API POST/GET/DELETE → Flutter quote detail/form.

Workspace isolation tested (cross-workspace delete 404s). Cancelled quotes remain blocked.

### Phase 6 — Invoice create experience — COMPLETE

| Behavior | BEFORE | AFTER |
|----------|--------|-------|
| Issue immediately on create | WEB-ONLY / API yes, Flutter always draft | COMPLETE Flutter `invoice_status=issued` |
| Attachments during create | PARTIAL Flutter (detail only) | COMPLETE multipart `attachments[]` + `items_json` |
| Walk-in / contract / project / tax | COMPLETE | COMPLETE |
| Server tax authority | COMPLETE | COMPLETE — client totals still stripped |

### Phase 7 — Statements — COMPLETE

Flutter `StatementScreen` uses `CustomerStatementService` via `GET /statements`: customer picker/search, date range, opening/closing, debit/credit, description, invoice reference, period totals, PDF, CSV. Pagination of the statement itself is a single period document (same as Web show), not a fake client ledger.

### Phase 8 — Payments / receipts — COMPLETE

| Surface | BEFORE | AFTER |
|---------|--------|-------|
| Payment filters (search, customer, date, method, status, treasury, reference) | PARTIAL Flutter | COMPLETE |
| Payment invoice_id filter | API/Web yes, Flutter no | COMPLETE |
| Payment reverse | COMPLETE | COMPLETE |
| Receipt filters + invoice_id | PARTIAL Flutter | COMPLETE |
| Receipt PDF / send / delivery history | COMPLETE | COMPLETE |

### Phase 9 — People / financial obligations — COMPLETE

Finance employees, payroll records, advances + repay, allowances/bonuses/deductions/adjustments, outstanding KPIs, settlement history, GL posting where already implemented. Not HASEM HR. Attendance/leave/recruitment remain NOT-FINANCE and were not built.

### Phase 10 — Expense / purchase — COMPLETE for genuine Finance capability

| Area | BEFORE | AFTER |
|------|--------|-------|
| Expense recurring frequency / next due | PARTIAL Flutter | COMPLETE form + API update |
| Expense supplier / treasury / tax / attachment | COMPLETE | COMPLETE |
| Purchase inbox filters + pipeline | PARTIAL | COMPLETE |
| Purchase issue-on-create | WEB-ONLY | COMPLETE API + Flutter |
| Purchase attachments | WEB via invoice attachments | COMPLETE `/purchases/{id}/attachments` + Flutter |
| Purchase record payment | WEB via invoice show | COMPLETE `POST /purchases/{id}/payments` + Flutter |
| Purchase PDF / issue / cancel | COMPLETE | COMPLETE |
| Expense PDF | MISSING (Web has attachment, not PDF) | MISSING — no expense PDF engine exists; not invented |

### Phase 11 — Contracts / billing schedules — COMPLETE

CRUD, terms, items, amount, status, attachments, PDF, billing schedules, frequency, next run, auto issue, generate, activate, pause, cancel, close. Duplicate billing occurrence protection remains (`billing_occurrence_key` unique + 409 concurrent message). Not re-implemented; verified present.

### Phase 12 — Dashboard / reports — COMPLETE

Dashboard sales/purchases/VAT/receivables exclude draft/cancelled. Reports (P&L, Balance Sheet, Trial Balance, GL, AR/AP aging, Cash Flow, inventory valuation) remain server-authoritative. Flutter displays `report()` JSON via `FinanceReportView` and reloads when the report chip changes. Flutter does not calculate ledgers.

### Phase 13 — Permissions — COMPLETE with documented elevation

Web `FinanceBaseController::authorizeFinance` = API `AuthorizesFinanceApi::isElevatedFinanceMember` = Flutter `financePermissionMap` visibility. Elevation for owner/admin/manager was **not** tightened. Reason: workspace owners operate Finance without every Spatie key; removing elevation would lock legitimate owners. Cashiers are not elevated. Mapped aliases remain: `statements.view` ← `invoices.view`; `customers.view` ← finance/invoices/customers.manage.

Invoice journals on API/Flutter follow the same `accounting.view` map (including elevation), so owners are not locked out of GL chrome that Web hid behind a raw Spatie `can('accounting.view')` check.

### Phase 14 — Error contract — COMPLETE for Finance domain

| HTTP | Mapping |
|------|---------|
| 401 | Unauthenticated |
| 403 | permission / workspace |
| 404 | binding / abort_unless |
| 409 | true conflicts only (already paid, duplicate numbers, concurrent billing occurrence) |
| 422 | validation + other `RuntimeException` domain failures, including “quotes must be issued+accepted” |

Quote convert of a draft quote stays **422**, not 409. Flutter maps common English API messages to Arabic.

### Phase 15 — Security — COMPLETE (no weakening)

Workspace global scope, SecureUpload, checkout amount/currency verification, webhook signature + replay (`WebhookEvent` firstOrCreate), payment amount ≤ due + tolerance (over-due webhook **refuses** and logs; it does not invent an overpay settlement). Secrets never sent to Flutter.

### Phase 16 — Visual parity — COMPLETE for Finance chrome

Arabic RTL, dense tables, professional forms, pipeline/filter bars, status chips, money rows, empty/loading/error/confirmations. Finance is not POS/Cashier UI. Remaining visual debt is polish, not missing billed operations.

### Phase 17 — Testing — COMPLETE (with known env skip)

Executed:

| Suite | Result |
|-------|--------|
| Flutter analyze | clean |
| Flutter tests (`apps/hasim_finance`) | 81 passed |
| Laravel `tests/Feature/Feature/Finance` | 337 passed, 2 skipped, 1 error |
| Checkout / people / Phase7 / Phase8 / Unit EInvoicing / webhook / tax | 139 passed, 1 skipped |
| New `FinanceBillingParityImplementationTest` | 9 passed |

Known environment error (pre-existing, not deleted): `FinanceFlutterFeatureParityTest::test_contract_attachments_logo_dashboard_filters_and_bank_matching` — `GD extension is not installed.`

### Phase 18 — this document — COMPLETE

---

## 4. Feature matrix AFTER

| Feature | Web | API | Flutter | AFTER |
|---------|-----|-----|---------|-------|
| Invoice list filters + pipeline | yes | yes | yes | COMPLETE |
| Invoice create/edit/issue/cancel/PDF/email/remind | yes | yes | yes | COMPLETE |
| Issue on create + create-time attachments | yes | yes | yes | COMPLETE |
| Tax breakdown / issued_at / supply_date | yes | yes | yes | COMPLETE |
| ZATCA XML/QR foundation download | yes | yes | yes | COMPLETE |
| FATOORA clearance | no | no | no | MISSING |
| Quote lifecycle + convert once to draft | yes | yes | yes | COMPLETE |
| Quote attachments | yes | yes | yes | COMPLETE |
| Walk-in quotes | no | no | no | MISSING (quotes require `customer_id`) |
| Payments + reverse + filters | yes | yes | yes | COMPLETE |
| Receipts PDF/send/deliveries | yes | yes | yes | COMPLETE |
| Statements | yes | yes | yes | COMPLETE |
| Contracts + schedules | yes | yes | yes | COMPLETE |
| Expenses recurring | yes | yes | yes | COMPLETE |
| Purchases attachments/payments/issue | yes | yes | yes | COMPLETE |
| People/payroll/advances | yes | yes | yes | COMPLETE |
| Reports ledger set | yes | yes | yes | COMPLETE |
| Merchant KYC | yes | payments module | no | WEB-ONLY |
| Invoice journal chrome | yes | yes | yes | COMPLETE |
| Alpine tax preview | yes | n/a | display-only | WEB-ONLY display |
| POS cashier invoices | pos.* | /pos-invoices | no | NOT-FINANCE |

---

## 5. Remaining items (exact reasons)

### WEB-ONLY

| Item | Exact reason |
|------|----------------|
| Merchant KYC (`payments/merchant`) | Lives under workspace payments onboarding, outside the Finance module prefix. Flutter Finance is not the merchant-of-record console. |
| Alpine invoice tax preview | Client display-only calculator on Web create. Server tax engine remains authoritative. Flutter preview is also display-only and must not become a second engine. |
| Accounting dashboard extra Blade chrome / cashbox placeholder | Blade accounting hub widgets not required for billed AR/AP operations; Flutter accounting hub already consumes `/accounting`. |
| POS UI | NOT-FINANCE billing core; listed here only because it is a Web surface. |

### SECURITY-RESTRICTED

| Item | Exact reason |
|------|----------------|
| Payment provider secrets (`config/payment.php`) | Gateway credentials. Flutter only receives checkout URLs. |
| Webhook secrets | Required to verify provider callbacks. Never sent to clients. |
| ZATCA / CSID private keys and certificates | Backend e-invoice security records. Flutter may download issued XML/QR payloads only. |
| Stamp private material | Production signer fails closed. Private material stays on server. |
| Sanctum personal access tokens | Device credentials. Not embedded in document payloads. |

### BACKEND-ONLY

| Item | Exact reason |
|------|----------------|
| Tax engine / Money rounding | Server authority. Flutter must not persist client totals. |
| `CustomerBalanceService` | AR truth. `customers.balance` is cache. |
| GL posting / hash chain / scheduled billing and reminders | Jobs and services. Flutter displays results. |
| Webhook verification + replay table | Must stay server-side. |
| Checkout amount > due refusal | Security: webhook does not overpay the invoice. Operator recovery is the application log, not an invented settlement. |

### MISSING (by product choice, not incomplete wiring)

| Item | Exact reason |
|------|----------------|
| FATOORA clearance / reporting HTTP | Repository has no live FATOORA integration requirements. Implementing it would fake production ZATCA. |
| Production cryptographic stamp | Existing signer fails closed. Not faked. |
| Walk-in quotes | Web and API require `customer_id` on quotes. Not invented. |
| Seeded dedicated `customers.view` / `statements.view` Spatie keys | API map aliases them from invoices/finance. Seeding extra keys without a permission redesign would not change enforcement. |
| HTTP `Idempotency-Key` header | Not in existing Finance contract. Quote convert and payment references already have domain idempotency. |
| Expense PDF | No expense PDF service exists on Web/API. Attachment download exists. |

### NOT-FINANCE

| Item | Exact reason |
|------|----------------|
| POS cashier invoices / `PosTaxCalculator` | Parallel document family. Shares e-invoice factory; does not own Finance AR. |
| Appointments payment links | Booking module, not `InvoiceService`. |
| Inbox / WhatsApp / Instagram / Messenger | Messaging products. Email logs are delivery infrastructure only. |
| Workspace `/employees` invitations | Platform membership, not `finance_employees`. |
| Storefront `Order` payment settle | Shared `payments` table, not Finance AR. |

### BROKEN

None remaining for the billed Finance path. The forensic BROKEN ZATCA copy and placeholder `has_qr` are fixed.

### PARTIAL

None remaining that the forensic audit classified as a genuine Finance gap **and** that the existing backend could support. Remaining incompleteness is MISSING FATOORA, WEB-ONLY merchant KYC, or SECURITY-RESTRICTED secrets.

---

## 6. Tests added

Laravel: `tests/Feature/Feature/Finance/FinanceBillingParityImplementationTest.php`

- Dashboard sales exclude draft/cancelled (issued 200 + 15% VAT = `230.00`)
- Issued invoice field parity + foundation ZATCA flags + XML + Web copy
- Issued invoice `journal_entries` present
- Inbox sort + pipeline meta
- Quote attachments round-trip + workspace isolation
- Quote convert idempotent 200 / draft-quote 422
- Payments/receipts filters
- Expense recurring_frequency update
- Purchase issue + attachments + payment + payments?invoice_id
- Invoice line `exemption_code` persistence

Flutter: `field_coverage_test.dart` journal entries, tax breakdown, exemption code payload.

---

## 7. Migrations / APIs

**Migration added:** `database/migrations/2026_09_10_230000_create_finance_quote_attachments_table.php`

**APIs added/changed:**

- `GET /api/finance/v1/sales-invoices/{invoice}/xml`
- `GET /api/finance/v1/sales-invoices/{invoice}/qr`
- sales-invoices index `sort`, `direction`, `pipeline`, `totals`
- `POST/GET/DELETE /api/finance/v1/quotes/{quote}/attachments[/{attachment}]`
- `POST /api/finance/v1/purchases/{invoice}/payments`
- `POST/GET/DELETE /api/finance/v1/purchases/{invoice}/attachments[/{attachment}]`
- purchase store multipart attachments + issue-on-create
- payment/receipt index filters (search, invoice, customer, dates, method, treasury, reference)
- expense update supplier/category/treasury/recurring
- invoice detail `tax_breakdown`, `issued_at`, `supply_date`, `zatca`, `journal_entries`, `audit`

Web: `invoices.xml`, `invoices.qr`, quote attachment routes.

---

## 8. Flutter screens changed

- Invoice inbox, form, detail (`invoices_screens.dart`, `invoice_detail_widgets.dart`)
- Quotes (`quotes_screens.dart`)
- Payments, receipts, statements, purchases, expenses, reports (`module_screens.dart`)
- People/payroll/advances (already complete; unchanged this pass except shared chrome)
- Models + `finance_api.dart` + l10n

---

## 9. Verification note

Laravel → API flows for dashboard KPIs, invoice fields, XML, quote attachments, quote convert, purchase attachments/payments, exemption codes, and journals were executed as HTTP feature tests (not screen-only assertions).

Flutter widget/field tests cover inbox/detail/forms against the real API client contract with a fake HTTP layer. A live desktop Flutter session was not available in this environment; billed behavior was verified through the API the Flutter client calls.
