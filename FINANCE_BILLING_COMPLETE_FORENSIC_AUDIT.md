# FINANCE / BILLING — COMPLETE FORENSIC AUDIT

**Product:** HASEM Finance (Laravel source of truth + Flutter client `apps/hasim_finance`)  
**Audit type:** Read-only forensic inventory. No business logic, APIs, screens, migrations, permissions, or tests were changed.  
**Repository evidence date:** 10 September 2026  
**Branch inspected:** `cursor/finance-billing-forensic-audit-9bf7` at commit `88b9992` (invoice details redesign already present)  
**Method:** Trace HTTP → controller → validation → service → model/database → events/jobs → response. Compare Laravel Web vs `/api/finance/v1` vs Flutter.  
**Tests run:** none (audit-only; existing tests were inventoried, not executed).

Status vocabulary used throughout (exactly one per feature):

| Status | Meaning in this report |
|--------|------------------------|
| COMPLETE | Implemented and wired through the real backend/API; not merely a screen or route |
| PARTIAL | Exists but missing fields, actions, filters, enforcement, or a client |
| MISSING | Not found in repository for this domain |
| BROKEN | Code exists but contradicts itself or cannot complete the claimed job |
| WEB-ONLY | Exists in Laravel Web UI; not equivalently exposed to Flutter |
| API-ONLY | Exists on Finance API; not equivalently present in Web and/or Flutter |
| BACKEND-ONLY | Server implementation with no product UI (or must stay server-side) |
| FLUTTER-ONLY | Flutter surface with no matching Web/API support |
| NOT-FINANCE | Adjacent product (POS, Booking, Inbox, platform HR) |
| SECURITY-RESTRICTED | Must remain backend-only (secrets, keys, webhook verification) |

---

## 1. Executive summary

Laravel already contains a **real billing engine**, not a prototype: invoices, quotes, payments, receipts, credit/debit notes, customer statements, checkout, email delivery, PDFs, GL posting, billing schedules, payroll-as-finance, and a ZATCA **foundation** (snapshots, UBL XML, QR tags 1–6, hash chain, stamp scaffolding).

Flutter Finance is a **client of that engine**. Totals, tax, settlement, numbering, and ZATCA artifacts are **SERVER AUTHORITATIVE**. Flutter displays server money strings and does not persist client-computed tax.

The billing system is **operationally complete for core AR** (draft → issue → pay/partial/overdue → receipt → reverse → cancel with payment guards) and **commercially complete for quotes** (draft → issue → send → accept/reject → convert once to a **draft** invoice). It is **not** ZATCA Generation/Integration Phase 2 (no FATOORA CSID, no clearance/reporting HTTP). Production cryptographic stamp **fails closed**.

Largest gaps are not “missing invoice CRUD”. They are:

1. **Filter/inbox parity** — Web invoice inbox has date, customer, project, contract, payment method, type; Flutter list is search + lifecycle chips only.  
2. **ZATCA product exposure** — Backend e-invoice XML/QR/stamp APIs exist; Flutter sales client only receives `zatca{requirement, tax_document_subtype, has_qr}`. Web invoice show even states QR/XML are not generated, which is **stale relative to `InvoiceIssueService`**.  
3. **Quote attachments** — invoices and contracts have attachments; quotes do not.  
4. **Authorization model** — no `FinanceInvoice` Policy; HTTP controllers authorize; jobs/webhooks/schedulers call services without HTTP auth (by design). Owner/admin/manager **elevate** past permission keys.  
5. **`customers.balance` cache** is not AR source of truth; `CustomerBalanceService::outstanding` is.  
6. **POS cashier invoices** are a parallel document family. They share e-invoice infrastructure. They are **NOT-FINANCE billing core**.

### Completeness scores (evidence-based, 0–100)

| Surface | Score | Status |
|---------|------:|--------|
| Laravel Backend | 86 | COMPLETE for core AR; PARTIAL ZATCA Phase 2 |
| Laravel Web | 82 | COMPLETE core; PARTIAL ZATCA UI / purchase form split |
| Finance API | 88 | COMPLETE core; API-ONLY e-invoice XML/QR/stamp |
| Flutter Finance | 74 | PARTIAL filters, ZATCA, quote attachments, statement index |
| Web → API parity | 90 | COMPLETE for billed operations; WEB-ONLY some Blade-only chrome |
| API → Flutter parity | 76 | PARTIAL filters and compliance surfaces |
| Feature parity | 78 | See matrix §30 |
| Field parity | 80 | See matrix §31 |
| Invoice parity | 82 | Form COMPLETE; details PARTIAL (tax breakdown, issued_at, ZATCA) |
| Payment parity | 85 | COMPLETE settlement; PARTIAL list filters |
| Quote parity | 84 | COMPLETE lifecycle; MISSING attachments |
| ZATCA | 45 | BACKEND-ONLY foundation; SECURITY-RESTRICTED keys; no clearance |
| PDF | 88 | COMPLETE invoice/quote/receipt/credit-note/contract/statement |
| Email | 85 | COMPLETE invoice/quote/receipt/reminder; retry is job/queue level |
| Checkout | 80 | COMPLETE create/reuse/webhook settle; WEB-ONLY merchant onboarding UI |
| Reports | 80 | COMPLETE API ledger reports; Flutter viewer exists |
| Security | 78 | Workspace scope COMPLETE; webhook verify in job; elevation bypass |
| Permissions | 72 | Keys exist; no policies; Flutter getters omit some keys |
| Testing | 70 | Strong Laravel Finance/e-invoice/checkout tests; Flutter widget/parity tests; no live gateway tests |

**How complete is Billing?**  
Core sales billing (create draft, tax, issue, GL, pay, receipt, reverse, cancel, PDF, email, remind, checkout) is **COMPLETE** on Laravel and **mostly COMPLETE** on Flutter. Compliance (ZATCA clearance) is **BACKEND-ONLY / MISSING production integration**. Inbox-grade filtering and some document attachments are **PARTIAL**. Do not treat a Flutter screen as complete unless the API action is called.

---

## 2. Product boundaries

### In scope (Finance / Billing)

- Workspace-scoped Finance module under `workspace.feature:finance` (`routes/web.php` ~355–516).  
- Finance API `/api/finance/v1` (`routes/api.php` + `routes/finance-api.php`).  
- Flutter app `apps/hasim_finance`.  
- Shared payment webhooks when `Payment` is billable `finance_invoice`.  
- Company **people / salaries / advances / allowances / bonuses / deductions** stored as Finance employees (`finance_employees`, not HASEM HR).  
- Accounting/GL, treasury, fiscal years **where they post from billing**.  
- Contracts + `finance_billing_schedules` that generate sales invoices.

### Out of scope unless coupling is real (NOT-FINANCE)

| Product | Coupling found | Classification |
|---------|----------------|----------------|
| POS / Cashier | `pos_cashier_invoices`, `PosInvoiceController`, `Order.finance_invoice_id`, separate `PosTaxCalculator` | NOT-FINANCE billing. Shares e-invoice factory and checkout port. POS issue path often does **not** create `finance_invoices` (Phase 3 tests historically assert null). |
| Booking / Appointments | Booking payment-link routes under `workspace.feature:appointments` | NOT-FINANCE. May reuse `PaymentService` checkout, not `InvoiceService`. |
| Inbox / WhatsApp / Instagram / Messenger | Email logs used by `finance_document_deliveries.email_log_id` | Delivery infrastructure only. |
| Platform employee invitations | `EmployeeInvitationController` at workspace `/employees` | NOT-FINANCE (workspace membership). Finance people live at `/finance/employees`. |
| Orders (storefront) | `PaymentService::settleOrderByNumber` | Shared payments table. Not Finance AR. |

### Isolation rule

Laravel is source of truth. Flutter must not authoritatively compute tax/totals. ZATCA private keys, payment provider secrets, and webhook secrets are **SECURITY-RESTRICTED**.

---

## 3. Laravel architecture

### 3.1 Tenancy and HTTP entry

| Layer | Evidence |
|-------|----------|
| Workspace global scope | `app/Models/WorkspaceScopedModel.php` — route binding returns null if authenticated user has no workspace |
| Trait | `BelongsToWorkspace` on Finance models |
| Web group | `routes/web.php` `workspace.feature:finance` prefix `finance` names `workspace.finance.*` |
| API group | `routes/api.php` prefix `finance/v1`; middleware `auth:sanctum`, `workspace.resolve`, `workspace.member`, `throttle:cashier-api`; writes also `throttle:mobile-write` |
| Web authz | `app/Http/Controllers/Workspace/Finance/FinanceBaseController::authorizeFinance` |
| API authz | `AuthorizesFinanceApi::authorizeFinanceApi` / `HandlesFinanceClient::clientActor` |
| No Eloquent Policy | No `FinanceInvoicePolicy`. POS has `PosCashierInvoicePolicy` (NOT-FINANCE). |

**Elevation:** `AuthorizesFinanceApi::isElevatedFinanceMember` treats workspace `owner|admin|manager` as allowed even without the permission key. Same pattern is documented as matching Web. **PARTIAL** least-privilege: keys exist but managers bypass them.

**Services used by jobs/webhooks/console bypass HTTP auth by design.** Settlement still goes through `InvoicePaymentService::recordPayment` with invoice locks and reference uniqueness.

### 3.2 Core service map (billing)

| Concern | Class | Path |
|---------|-------|------|
| Invoice CRUD / issue / cancel / snapshots | `InvoiceService` | `app/Services/Finance/InvoiceService.php` |
| Document vs payment status | `InvoiceStateService` | `app/Services/Finance/InvoiceStateService.php` |
| Tax | `TaxCalculationService` | `app/Services/Finance/Tax/TaxCalculationService.php` |
| Money | `Money` | `app/Support/Money/Money.php` |
| Payments | `InvoicePaymentService` | `app/Services/Finance/InvoicePaymentService.php` |
| Receipts | `ReceiptService` | `app/Services/Finance/ReceiptService.php` |
| Checkout URL only | `InvoiceCheckoutService` | `app/Services/Finance/InvoiceCheckoutService.php` |
| Gateway + webhook settle | `PaymentService` | `app/Services/Payment/PaymentService.php` |
| Quotes | `QuoteService` | `app/Services/Finance/QuoteService.php` |
| Quote email | `QuoteEmailService` | `app/Services/Finance/QuoteEmailService.php` |
| Credit/debit notes | `CreditNoteService` | `app/Services/Finance/CreditNoteService.php` |
| Customer AR | `CustomerBalanceService` | `app/Services/Finance/CustomerBalanceService.php` |
| Statements | `CustomerStatementService` | `app/Services/Finance/CustomerStatementService.php` |
| Invoice email | `InvoiceEmailService` | `app/Services/Finance/InvoiceEmailService.php` |
| Manual remind | `InvoiceReminderEmailService` | `app/Services/Finance/InvoiceReminderEmailService.php` |
| Scheduled remind | `InvoiceReminderService` | `app/Services/Finance/InvoiceReminderService.php` |
| PDF invoice | `PdfInvoiceService` | `app/Services/Finance/PdfInvoiceService.php` |
| List filters | `InvoiceInboxService` | `app/Services/Finance/InvoiceInboxService.php` |
| Billing schedules | `BillingScheduleService` | `app/Services/Finance/BillingScheduleService.php` |
| GL | `AccountingService` | `app/Services/Finance/AccountingService.php` |
| E-invoice issue | `InvoiceIssueService` | `app/Services/EInvoicing/InvoiceIssueService.php` |
| API presenter | `FinanceClientPresenter` | `app/Services/Finance/Api/FinanceClientPresenter.php` |
| Dashboard KPIs | `DashboardService` | `app/Services/Finance/DashboardService.php` |
| Ledger reports | `LedgerReportService` | `app/Services/Finance/LedgerReportService.php` |

### 3.3 Typical invoice create flow (API)

```
POST /api/finance/v1/sales-invoices
  middleware: auth:sanctum, workspace.resolve, workspace.member, throttle:mobile-write
  SalesInvoiceController::store
    clientActor(..., 'invoices.create')  [and invoices.issue if invoice_status=issued]
    invoicePayload() validation
    HandlesFinanceClient::documentItemsFromRequest()
      UNSETS client total/tax_amount/taxable_amount/subtotal   ← SERVER AUTHORITATIVE
    InvoiceService::create()
      TaxCalculationService::calculateDocument()
      persist draft (always)
      optional InvoiceService::issue()
        tax reconcile, GL, inventory, snapshots, InvoiceIssueService (ZATCA prepare)
    FinanceClientPresenter::invoiceDetail()
```

Web uses the same `InvoiceService` from `app/Http/Controllers/Workspace/Finance/InvoiceController.php`.

### 3.4 Observers / jobs / schedule

| Mechanism | Evidence |
|-----------|----------|
| Payment observer | `FinanceInvoicePaymentObserver` → `InvoiceService::syncPaymentStatus` |
| Webhook HTTP | `POST /api/webhooks/payments/{provider}` → `PaymentWebhookController::handle` 202 → `ProcessPaymentWebhook` job (`tries=5`) |
| Console | `routes/console.php`: `finance:invoices:refresh-payment-status` hourly; `finance:invoices:send-reminders` daily 08:00; `finance:billing-schedules:generate` daily 01:15 |

---

## 4. Database architecture

All billing tables are workspace-owned (`workspace_id` FK, typically `cascadeOnDelete`). Finance models inherit workspace scoping.

### 4.1 `finance_settings` (1:1 workspace)

**Migration:** `database/migrations/2026_08_22_164500_create_finance_module_tables.php` plus later columns (`allow_manual_invoice_numbers`, quote/receipt prefixes).

| Column | Type / notes | Business meaning |
|--------|----------------|------------------|
| company_name, company_name_ar | string nullable | PDF/company snapshot |
| logo_path | string nullable | PDF logo |
| vat_number, commercial_registration | string nullable | Tax identity |
| address fields | building/street/district/city/postal/country_code default SA | ZATCA address snapshot source |
| phone, email | nullable | |
| currency | char 3 default SAR | |
| invoice_prefix / next_invoice_sequence | INV / 1 | Numbering |
| allow_manual_invoice_numbers | boolean, backfilled true | Manual number allowed historically |
| quote_prefix / next_quote_sequence | Q / 1 | |
| receipt_prefix / next_receipt_sequence | RCT / 1 | |
| default_payment_terms | nullable | |
| default_vat_rate | decimal 5,2 default 15 | Workspace VAT default |
| zatca_integration_mode, zatca_certificate_serial, zatca_last_synced_at | nullable | Placeholder settings — not a live FATOORA client |
| unique(workspace_id) | | |

### 4.2 `finance_invoices`

**Created:** same foundation migration. **Hardened** by `2026_08_28_050000_add_invoice_and_payment_status_to_finance_invoices_table.php`, `2026_09_02_080000_enhance_finance_billing_module.php`, `2026_09_02_210000_add_billing_occurrence_key_to_finance_invoices.php`, `2026_09_07_141500_harden_finance_invoice_domain.php`, `2026_09_07_151000_add_tax_engine_persistence_to_finance_documents.php`.

| Column | Meaning |
|--------|---------|
| type | `sales` \| `purchase` |
| invoice_number | unique per workspace |
| invoice_status | `draft` \| `issued` \| `cancelled` (split status) |
| payment_status | `unpaid` \| `partial` \| `paid` \| `overdue` |
| status | **legacy** combined enum; still written via `toLegacyStatus` |
| customer_id | nullable; walk-in uses `customer_name` |
| supplier_id | purchase invoices |
| contract_id, billing_schedule_id, billing_occurrence_key | recurring uniqueness |
| project_id | optional |
| issue_date, due_date, supply_date, issued_at, cancelled_at | |
| currency | default SAR |
| subtotal, discount, taxable_amount, tax_amount, total | SERVER calculated |
| amount_paid, amount_due, amount_credited, amount_debited | due = total + debited − credited − paid |
| tax_profile_type, tax_rate, tax_price_mode, tax_breakdown | tax engine |
| tax_document_subtype | `standard` \| `simplified` |
| zatca_requirement | `not_required` \| `required` (internal flag) |
| zatca_uuid, zatca_qr_code, zatca_xml_hash | **placeholder columns**; real e-invoice store is dedicated tables |
| payment_terms, notes | |
| company_snapshot, recipient_snapshot, pdf_snapshot | frozen at issue |
| last_reminder_sent_at, reminder_stage | reminders |
| created_by, issued_by | users |
| timestamps + soft deletes | |
| unique(workspace_id, invoice_number) | |
| unique billing_occurrence_key when present | prevents duplicate schedule invoices |

### 4.3 `finance_invoice_items`

qty decimal(12,3); money decimal(14,2); `product_id` nullable (free-text); `tax_profile_type`, exemption fields, `unit`/`unit_code`, metadata JSON. FK invoice cascade.

### 4.4 `finance_invoice_payments`

method enum cash/bank_transfer/card/other; amount; reference; status posted/reversed; reversed_at/by; treasury_account_id. **Unique (`workspace_id`, `invoice_id`, `reference`)** — payment idempotency when reference is set.

### 4.5 `finance_invoice_attachments`

file_path, file_name, file_type, file_size, uploaded_by. Index (workspace_id, invoice_id).

### 4.6 `finance_receipts`

1:1 `payment_id` unique. invoice_id, customer_id nullable, receipt_number unique per workspace, amount, method, status posted/voided, voided_at/by. Purchase payments do **not** create customer receipts (`ReceiptService`).

### 4.7 `finance_quotes` / `finance_quote_items`

customer_id **required** restrictOnDelete (no walk-in quotes). status draft/issued/cancelled. outcome pending/accepted/rejected/converted. converted_invoice_id **unique** (one invoice per converted quote). Tax columns + snapshots. Soft deletes. **No quote attachment table.**

### 4.8 `finance_credit_notes` / `finance_credit_note_items`

type `credit` \| `debit`. status draft/issued/cancelled. Linked to invoice. Number unique per workspace. Issuing adjusts invoice `amount_credited` / `amount_debited` then `syncPaymentStatus`.

### 4.9 `finance_billing_schedules`

status draft/active/paused/completed/cancelled. frequency, interval_count, total_occurrences, generated_count, amount, auto_issue, item_snapshot JSON, next_run_on. Generates `finance_invoices` with `billing_occurrence_key`.

### 4.10 `finance_document_deliveries`

document_type + document_id (quote/invoice/receipt/invoice_reminder). channel default email. status sending/sent/failed. email_log_id, attachment path, error, meta JSON.

### 4.11 `issued_document_snapshots` + e-invoice tables

- `issued_document_snapshots` — immutable commercial snapshot at issue.  
- `e_invoice_documents` — XML/document records.  
- `e_invoice_security_records` — ICV/PIH/hash.  
- `e_invoice_certificates` — CSID metadata storage.  
- `e_invoice_cryptographic_stamps` — stamp rows; production signer not live.  
- `egs_units` — EGS unit registry.

### 4.12 Shared `payments` (platform checkout)

Not `finance_invoice_payments`. Used for Stripe/HyperPay/Local checkout links. `billable_type` / `billable_id` for finance invoices. Settlement writes a **finance** payment with reference `checkout:{payment_id}`.

### 4.13 `webhook_events`

unique (workspace_id, provider, external_event_id). Replay protection.

### 4.14 GL / treasury / tax / people (billing-adjacent)

`finance_accounts`, `finance_journal_entries` (+ lines), `finance_fiscal_years`, `finance_accounting_periods`, `finance_treasury_accounts`, `finance_treasury_transfers`, `finance_bank_statements` (+ lines), `finance_tax_rates`, `finance_suppliers`, `finance_expenses` (+ categories), `finance_employees`, `finance_employee_payroll_records`, `finance_salary_advances` (+ repayments), `finance_payroll_adjustments`, `finance_price_lists` (+ items), `finance_purchase_orders` (+ items), `finance_projects`, `customers` (party_type, vat, CR, address, payment_terms, **balance cache**).

### 4.15 POS (NOT-FINANCE)

`pos_cashier_invoices`, `pos_cashier_invoice_items`. Do not treat as sales billing.

---

## 5. Entity relationship map

```
Workspace
  ├─ FinanceSetting (1:1)
  ├─ Customer
  │    ├─ FinanceQuote (1:N) ── FinanceQuoteItem
  │    │                         └─ converted_invoice_id ──► FinanceInvoice (unique)
  │    ├─ FinanceInvoice type=sales (1:N)
  │    │    ├─ FinanceInvoiceItem
  │    │    ├─ FinanceInvoicePayment ──1:1── FinanceReceipt
  │    │    ├─ FinanceInvoiceAttachment
  │    │    ├─ FinanceCreditNote (credit|debit) ── items
  │    │    ├─ FinanceDocumentDelivery (polymorphic-by-columns)
  │    │    ├─ IssuedDocumentSnapshot / EInvoiceDocument
  │    │    ├─ platform Payment (checkout billable)
  │    │    └─ FinanceJournalEntry (issue / payment / reversal)
  │    ├─ Contract ── FinanceBillingSchedule ── generated FinanceInvoice
  │    └─ outstanding = SUM(issued sales invoices.amount_due)  [NOT customers.balance]
  ├─ FinanceSupplier ── purchase FinanceInvoice / expenses / POs
  └─ FinanceEmployee ── payroll records / salary advances / adjustments
```

Due formula (`InvoiceStateService::resolveAmountDue`):

`round(max(0, total + amount_debited − amount_credited − amount_paid), 2)`

Tolerance `PAYMENT_TOLERANCE = 0.009`.

---

## 6. Invoice lifecycle

### 6.1 States (two axes)

**Document (`invoice_status`):** `draft` → `issued` → `cancelled`  
**Payment (`payment_status`):** `unpaid` | `partial` | `paid` | `overdue`  
Legacy `status` is a compatibility projection (`InvoiceStateService::toLegacyStatus`). Accessors on `FinanceInvoice` bridge old/new.

`InvoiceStateService::resolveInvoiceStatus`: incoming `sent/unpaid/partial/paid/overdue` map to **issued**.

Overdue: issued, due > tolerance, due_date in the past, unpaid remainder. Refreshed by `finance:invoices:refresh-payment-status` hourly and on payment sync.

### 6.2 Transitions

#### Create (always draft first)

| | |
|--|--|
| Who | `invoices.create` |
| Service | `InvoiceService::create` |
| Validation | sales: customer_id **or** customer_name; purchase: supplier_id; ≥1 item; issue_date required |
| DB | insert invoice+items+optional attachments; totals from tax engine |
| GL | none until issue |
| Issue immediately | if payload status/invoice_status resolves to issued, calls `issue()` in same transaction |
| Flutter | always sends `invoice_status: draft` (`InvoiceFormScreen`) |
| Idempotency | unique invoice_number; billing_occurrence_key unique for schedules |

#### Update draft

| | |
|--|--|
| Who | `invoices.edit` |
| Method | `InvoiceService::updateDraft` |
| Required state | draft only |
| Issued | financial lock (items/amounts/attachments) |

#### Delete draft

| | |
|--|--|
| Who | `invoices.delete` |
| Method | `InvoiceService::deleteDraft` |
| Issued/cancelled | refused |

#### Issue

| | |
|--|--|
| Who | `invoices.issue` |
| Method | `InvoiceService::issue` |
| Required | draft (idempotent if already issued — returns existing) |
| DB | invoice_status=issued, issued_at, issued_by, snapshots, payment_status recomputed |
| Tax | reconcile/recalculate |
| GL | sales: DR AR / CR Revenue / CR Output VAT (`AccountingService`) |
| Inventory | unless skip_inventory |
| ZATCA | `InvoiceIssueService` prepare (XML/QR/security foundation) — not clearance |
| Audit | issue audit via invoice service |
| Failure | transaction rollback |

#### Record payment

| | |
|--|--|
| Who | `payments.manage` |
| Method | `InvoicePaymentService::recordPayment` |
| Required | issued; amount > 0; amount ≤ due + tolerance |
| Already paid | exception |
| Idempotency | same (workspace, invoice, reference) returns existing + ensures receipt |
| DB | payment posted; invoice amount_paid/due/payment_status; treasury adjust |
| GL | DR cash/bank CR AR |
| Receipt | `ReceiptService::ensureForPostedPayment` for **sales** |
| Side effect | observer `syncPaymentStatus` |

#### Reverse payment

| | |
|--|--|
| Who | `invoices.reverse_payment` |
| Method | `InvoicePaymentService::reversePayment` |
| Idempotent | already reversed returns payment |
| GL | `payment_reversal` reversing entry |
| Receipt | void |
| Period guard | open fiscal period required |

#### Cancel

| | |
|--|--|
| Who | `invoices.cancel` |
| Method | `InvoiceService::cancel` |
| Idempotent | already cancelled returns |
| Blocked | posted payments sum > tolerance — must reverse or credit-note |
| Issued cancel | reverse GL + stock |
| Draft cancel | status cancelled without GL reverse of issue (never posted) |

#### Send email

| | |
|--|--|
| Who | `invoices.send` |
| Service | `InvoiceEmailService::send` |
| Required | issued sales (`isSendable`) |
| Side effects | delivery row; optional PDF; audit `invoice_sent`; **may attach checkout URL without marking paid** |

#### Remind

| | |
|--|--|
| Who | `invoices.remind` |
| Service | `InvoiceReminderEmailService::send` |
| Plus | scheduled `InvoiceReminderService` daily |

#### Checkout

GET availability / POST create: does **not** mark paid. See §11–12.

### 6.3 Flutter actions wired

`invoices_screens.dart` + `finance_api.dart`: issue, send, remind, PDF, record payment, checkout, reverse, credit-note navigation, cancel, delete draft, attachments upload/download/delete.

**Status: COMPLETE** for these actions through `/sales-invoices/...`.

---

## 7. Quote lifecycle

**Service:** `QuoteService`  
**Web:** `workspace.finance.quotes.*`  
**API:** `/quotes` CRUD + issue/cancel/accept/reject/convert/send/pdf  
**Flutter:** `quotes_screens.dart`, `finance_api.dart` quotes*

### States

- Document: `draft` → `issued` → `cancelled`  
- Outcome: `pending` → `accepted` | `rejected` | `converted`

### Transitions

| Action | Permission | Rule | Side effects |
|--------|------------|------|----------------|
| create | quotes.create | customer required; items; tax via same TaxCalculationService | draft |
| updateDraft | quotes.edit | draft only | |
| deleteDraft | quotes.delete | not locked | soft/hard delete in transaction |
| issue | quotes.issue | draft → issued; snapshots | no GL |
| send | quotes.send | issued | `QuoteEmailService` + delivery |
| accept | quotes.accept | issued, pending, not expired | **does not create invoice or GL**; audit `quote_accepted` |
| reject | quotes.reject | issued pending | audit `quote_rejected` |
| convert | quotes.convert | accepted, not expired, not converted | `InvoiceService::create` as **draft**; sets converted_invoice_id; audit `quote_converted` |
| cancel | quotes.cancel | | |

**Duplicate conversion:** prevented by:

1. Early return if `isConverted() && converted_invoice_id` (`QuoteService::convert`)  
2. Unique `converted_invoice_id` (`2026_09_10_180000_add_quote_outcome_and_conversion_columns.php`)  
3. `isConvertible()` “once only”

Converted invoice is **draft** and must be issued separately. Comment in `QuoteService::convert` states this explicitly.

**Walk-in quotes:** MISSING (customer_id constrained).  
**Quote attachments:** MISSING (no table, no API, no Flutter).  
**PDF:** COMPLETE `GET /quotes/{quote}/pdf`.  
**Free-text lines:** COMPLETE (product_id nullable like invoices).

**Status:** COMPLETE lifecycle on Web+API+Flutter except attachments (MISSING) and walk-in (MISSING).

---

## 8. Customer financial lifecycle

**Model:** `app/Models/Customer.php`  
**API:** `CustomerController` — index/store/show/update. Permission on index/show: `finance.view` (not `customers.view`).  
**Presenter:** `FinanceClientPresenter::customer` exposes `outstanding_balance`.  
**Flutter:** `CustomerRecord.outstandingBalance`; list gated by `customersView` which maps from bootstrap `customers.view` = `finance.view \|\| customers.manage \|\| invoices.view`.

### Master data fields (API + Flutter)

name, party_type, email, phone, whatsapp, vat_number, commercial_registration, address, building_number, street, district, city, postal_code, country_code, additional_number, payment_terms, notes, nested invoices/quotes/payments/receipts/contracts on show.

### Balance source of truth

`CustomerBalanceService::outstanding`:

```
SUM(amount_due) FROM finance_invoices
  WHERE workspace_id = ?
    AND type = 'sales'
    AND customer_id IN (...)
    AND invoice_status = issued
```

Comment on service: **`customers.balance` is a stored cache and must not be used for display or decisions.**

Walk-in invoices (`customer_id` null) **do not** roll into a customer outstanding.

**Status:** COMPLETE calculation on server; COMPLETE Flutter display of outstanding; cache column is BACKEND-ONLY / unused for API presenter.

---

## 9. Payment lifecycle

### Manual

`InvoicePaymentService::recordPayment` — see §6. Flutter `recordPayment`. Web `invoices.payments.store`.

### List/show/reverse (global)

API `GET /payments`, `GET /payments/{id}`, `POST /payments/{id}/reverse`. Flutter payments screens.

### Treasury

Optional `treasury_account_id`; else first bank then first cash. Balance adjusted on post/reverse.

### Online checkout

Does not mark paid. See §11–12.

### Idempotency

- Reference unique per invoice  
- Checkout settlement reference `checkout:{payment_id}` so webhook retries reuse the finance payment row  
- Observer syncs status

**Status:** COMPLETE.

---

## 10. Receipt lifecycle

| Step | Implementation |
|------|----------------|
| Create | Automatic on posted **sales** payment (`ReceiptService::ensureForPostedPayment`) |
| Number | `receipt_prefix` + sequence on settings |
| Status | posted \| voided |
| PDF | `PdfReceiptService` / `GET /receipts/{id}/pdf` |
| Email | `ReceiptEmailService` / `POST /receipts/{id}/send` permission `receipts.send` |
| Reverse | payment reverse voids receipt |
| UI | Web index/show/pdf/send; Flutter list/detail/send/pdf |

No standalone “create receipt” without payment. **Status:** COMPLETE for sales payments. Purchase: no customer receipt (by design).

---

## 11. Checkout lifecycle

**Service:** `InvoiceCheckoutService`  
**Comment in class:** confirmation must never be inferred from generating a URL.

### GET `/sales-invoices/{id}/checkout`

`availability()`:

- Not sales → `not_sales_invoice`  
- Not issued / cancelled / trashed → `invoice_not_collectible`  
- amount_due ≤ tolerance → `invoice_paid`  
- Else if pending platform Payment with link → `supported` with existing URL  
- Else merchant eligibility via `MerchantPaymentEligibilityService`  
- Else `ready` message: creating a link does not mean paid  

Permission on GET: `payments.view`.

### POST `/sales-invoices/{id}/checkout`

`createCheckout()`:

- Same collectibility  
- Reuse pending payment if amount within tolerance of current due  
- Else `BillableCheckoutPort::createCheckout` with amount = current `amount_due`, currency, metadata invoice_id/number/customer_id  

Permission: `payments.manage`.

Flutter: `checkoutAvailability` + `checkout`. Web: `invoices.checkout`.

**Opened but not paid:** pending `payments` row remains; invoice stays unpaid. No auto-expire in Finance service (gateway-dependent).

**Invoice amount changes after checkout:** reuse only if pending amount matches new due within 0.009; otherwise new checkout. Webhook settlement refuses if checkout amount > current due (`PaymentService::settleFinanceInvoicePayment`).

**Status:** COMPLETE for create/reuse/availability. Merchant onboarding UI is **WEB-ONLY** (`payments/merchant` outside finance prefix). Flutter cannot complete merchant KYC in-app.

---

## 12. Webhook lifecycle

```
POST /api/webhooks/payments/{provider}
  PaymentWebhookController::handle
    ALWAYS 202
    ProcessPaymentWebhook job (5 tries, 120s)
      PaymentService::processWebhook
        provider->verifyWebhook(headers, payload, rawBody)
        resolve workspace from payment/gateway/order
        WebhookEvent::firstOrCreate(workspace_id, provider: 'payment:{name}', external_event_id)
        if not recently created → return (replay)
        if !verified → status invalid, return
        if status !== paid → return
        lock Payment
        if finance billable → settleFinanceInvoicePayment
          amount/currency match vs verification
          invoice must be issued, not cancelled, not trashed
          checkout amount must not exceed due
          mark platform payment paid
          InvoicePaymentService::recordPayment(..., reference checkout:{id}, method other)
```

### Edge matrix

| Event | Behavior | Status |
|-------|----------|--------|
| Duplicate webhook | firstOrCreate; second ignored | COMPLETE idempotency |
| Forged webhook | `verifyWebhook` must fail; event invalid; no settle | COMPLETE **if** provider verifier is correct; HTTP layer does not verify before 202 |
| Amount differs | `financeWebhookMatchesPayment` false → no settle | COMPLETE |
| Currency differs | same | COMPLETE |
| Invoice cancelled / not issued | return without settle | COMPLETE |
| Already paid (finance) | recordPayment throws already paid **unless** same checkout reference hits unique and returns existing | COMPLETE for retries with same reference |
| Invoice amount changed down | checkoutAmount > due → return, no settle | COMPLETE (leaves platform payment possibly unpaid/paid without AR — see P1) |
| Invoice amount changed up | may underpay; partial | PARTIAL product policy |
| Reversal | Finance reverse is manual `reversePayment`; webhook reversal path not a first-class Finance flow in this service | PARTIAL |

**SECURITY-RESTRICTED:** webhook secrets stay on gateway config; never Flutter.

**Status:** COMPLETE replay + verify-in-job. HTTP 202 before verify is intentional but means unauthenticated traffic can enqueue jobs (**P2** abuse/cost).

---

## 13. PDF lifecycle

| Document | Generator | Route Web | Route API | Flutter |
|----------|-----------|-----------|-----------|---------|
| Sales/purchase invoice | `PdfInvoiceService` | `invoices.pdf` | `GET /sales-invoices/{id}/pdf` and `GET /invoices/{id}/pdf` | download/print via sales-invoices pdf |
| Quote | Pdf quote path in Finance PDF services | `quotes.pdf` | `GET /quotes/{id}/pdf` | yes |
| Receipt | `PdfReceiptService` | `receipts.pdf` | `GET /receipts/{id}/pdf` | yes |
| Credit/debit note | credit note PDF | `invoices.credit-notes.pdf` | `GET /credit-notes/{id}/pdf` | yes |
| Contract | contract PDF | `contracts.pdf` | `GET /contracts/{id}/pdf` | yes |
| Statement | Blade `statements.pdf` + DomPDF | statements show format | `GET /statements?format=pdf` | Flutter statement screen can request formats via API |

**Data:** issued invoices use snapshots (`snapshotsAreAuthoritative()`). Company logo from settings. QR: PDF may include QR when snapshot/e-invoice has it; Web show copy currently says QR not generated (**stale copy**, §17).

**Language/RTL:** Arabic Blade financial layout. Flutter opens server PDF bytes (`download_io.dart` / `download_web.dart`).

**Status:** COMPLETE generation. WEB-ONLY: some print chrome. Flutter uses same bytes.

---

## 14. Email lifecycle

| Mail | Service | Permission | Delivery row |
|------|---------|------------|--------------|
| Invoice | `InvoiceEmailService::send` | invoices.send | document_type invoice |
| Quote | `QuoteEmailService::send` | quotes.send | quote |
| Receipt | `ReceiptEmailService::send` | receipts.send | receipt |
| Reminder | `InvoiceReminderEmailService` + `InvoiceReminderService` | invoices.remind / scheduler | invoice_reminder |

Chain: controller → email service → persist `finance_document_deliveries` (sending) → mailer → sent/failed + error text → optional `email_logs`. Audit `invoice_sent` on invoice send.

Checkout URL may be included on invoice send **without** settlement.

Retry: queue worker / job tries; no dedicated Finance retry UI. Failed deliveries visible on invoice detail `deliveries` (API presenter + Flutter `DeliveryRecord`).

**Status:** COMPLETE. Flutter invoice detail shows deliveries.

---

## 15. Reminder lifecycle

| Path | Class | Trigger |
|------|-------|---------|
| Manual | `InvoiceReminderEmailService::send` | POST remind |
| Scheduled | `InvoiceReminderService` | `finance:invoices:send-reminders` daily 08:00 |
| Skip | amount_due ≤ tolerance | both |

Stages: upcoming / due / overdue (columns `reminder_stage`, `last_reminder_sent_at`). Requires issued unpaid/partial collectible invoices.

**Status:** COMPLETE backend + Web + Flutter remind action. Repeat policy is stage/timestamp based (not unlimited spam UI). **PARTIAL** if product expects configurable cadence per customer — not a rich reminder planner.

---

## 16. Contract / billing lifecycle

**Web/API/Flutter:** contracts CRUD, activate/close/cancel, PDF, attachments, billing-schedules store/activate/pause/cancel/generate.

`BillingScheduleService::generateOne` → `InvoiceService::create` with `billing_occurrence_key`. `auto_issue` can issue immediately. Unique occurrence prevents duplicates. Console `finance:billing-schedules:generate` daily 01:15.

Statuses: draft/active/paused/completed/cancelled.

**Status:** COMPLETE on API + Flutter (`module_screens.dart` contract detail attachments + schedule actions). Recurring is **job-driven**, not an in-process daemon.

---

## 17. ZATCA architecture

**Internal repo “Phase 1–10” is an engineering roadmap, not ZATCA Generation vs Integration.**

### WHAT EXISTS (BACKEND)

| Capability | Evidence | Status |
|------------|----------|--------|
| Tax document subtype standard/simplified | invoice columns + enums | COMPLETE |
| zatca_requirement flag | not_required/required | COMPLETE as **internal** flag |
| Issued snapshots | `IssuedSnapshotBuilder`, snapshot JSON columns | COMPLETE |
| UBL XML generation | `EInvoiceXmlGenerator`, `GET /invoices/{id}/xml` | COMPLETE generation |
| QR tags 1–6 | `EInvoiceQrService`, tests `Phase8ZatcaQrFoundationTest` | COMPLETE foundation |
| Hash / ICV / PIH | `InvoiceHashService`, `EInvoiceSecurityService` | COMPLETE chain storage |
| Cryptographic stamp **request** | `POST .../cryptographic-stamp` | PARTIAL — production throws `ProductionCryptoUnavailableException` (`requestProductionCryptographicStamp`: never) |
| FATOORA/CSID live signing | not implemented | MISSING |
| Clearance / reporting HTTP | not implemented | MISSING |
| Invoice columns zatca_* | placeholders | PARTIAL / unused as source of truth |

`InvoiceIssueService` documents: security service does **not** contact ZATCA/FATOORA.

### WHAT IS EXPOSED TO FLUTTER

`FinanceClientPresenter::invoiceDetail` → `zatca: { requirement, tax_document_subtype, has_qr }` where `has_qr` is `filled($invoice->zatca_qr_code)` (**placeholder column**, often empty even if e-invoice QR exists).

Flutter `InvoiceRecord` maps those three fields only. **No calls** to `/invoices/{id}/xml`, `/qr`, or `cryptographic-stamp` in `apps/hasim_finance`.

### WHAT IS WEB-ONLY

Web `invoices/show.blade.php` lines ~308–315 state in Arabic that ZATCA is not prepared and QR/XML are **not generated**. That copy is **stale vs `InvoiceIssueService`**. No Web buttons to download XML/QR from e-invoice tables on the sales invoice page.

### WHAT IS BACKEND-ONLY FOR SECURITY

Certificates, private keys, stamp material, `e_invoice_certificates` rows. Do **not** send to Flutter.

**Status:** BACKEND-ONLY foundation COMPLETE; production integration MISSING; Flutter SECURITY-RESTRICTED (correctly not given secrets) but also **missing safe artifacts** (XML/QR download of issued docs) → PARTIAL product; Web copy BROKEN/stale.

---

## 18. Reports

API `GET /reports/{report}` permission `reports.view` (`ReportController`).

| Key | Service method | CSV | Flutter |
|-----|----------------|-----|---------|
| profit-loss | `LedgerReportService::profitAndLoss` | yes | `/reports` viewer |
| balance-sheet | balanceSheet | yes | yes |
| trial-balance | trialBalance | yes | yes |
| general-ledger | generalLedger + account_id | yes | yes |
| ar-aging | aging(..., 'sales') | yes | yes |
| ap-aging | aging(..., 'purchase') | yes | yes |
| cash-flow | cashFlow | yes | yes |
| inventory-valuation | inventoryValuation | yes | yes |
| unknown | abort 404 | | |

Filters: `from`, `to` (default month), `account_id` for GL, `format=csv`.

Web: `workspace.finance.reports.index/show` same ledger service.

Dashboard KPIs: `DashboardService::metrics` (sales, purchases, expenses, VAT in/out, receivables, overdue counts) + `FinanceAnalyticsService::dashboard` with from/to/customer/product/project/lifecycle/payment_method.

**VAT hub / sales hub / billing hub:** `HubController` API + Flutter hub screens — aggregations, not a second tax engine.

**Status:** COMPLETE API+Web+Flutter for ledger set. Dashboard filter bar exists in Flutter; invoice **list** does not reuse the full inbox filter set (gap §21).

---

## 19. Dashboard

| Surface | Controller | Permission |
|---------|------------|------------|
| Web | `FinanceDashboardController` | finance.view (via FinanceBaseController) |
| API | `DashboardController` | finance.view |
| Flutter | `dashboard_screen.dart` `/dashboard` | financeEnabled + permissions |

Metrics from issued/all invoice sums as implemented in `DashboardService` (includes non-draft totals as coded — confirm consumers treat “total sales” as sum of `total` on type=sales **without excluding drafts** in the cloned query at lines 30–33: `FinanceInvoice::query()->where('type','sales')` then `sum('total')`. That **includes drafts** unless a global scope filters them. **PARTIAL / P1** if drafts inflate sales KPI).

Paid this period: posted payments in current month (`DashboardController`).

**Status:** PARTIAL pending confirmation that metrics exclude drafts/cancelled (code as read includes all rows of that type unless scopes apply). `FinanceInvoice` workspace scope does not exclude drafts.

---

## 20–22. Search, filters, pagination

### Invoice list

| Capability | Web (`InvoiceInboxService`) | API `GET /sales-invoices` | Flutter `InvoicesScreen` |
|------------|----------------------------|---------------------------|--------------------------|
| search | number, customer_name, statuses | same service | search box |
| lifecycle | draft/sent/partial/paid/overdue/cancelled | `lifecycle` | chips only |
| invoice_status / payment_status | yes | yes | API client supports; UI does not expose extra fields |
| type sales/purchase | yes | sales controller forces sales | purchases on `/purchases` |
| customer_id | yes | yes | MISSING UI |
| supplier_id | yes | purchases | purchases screens |
| currency | yes | yes | MISSING UI |
| from/to issue_date | yes | yes | MISSING UI |
| contract_id | yes | yes | MISSING UI |
| project_id | yes | yes | MISSING UI |
| payment_method | whereHas payments | yes | MISSING UI |
| sort | invoice_number, dates, total, due, id | check index | default API order |
| pagination | 15 Web | `per_page` in `_paged` (Flutter ~default) | page + per_page |
| pipeline counts / filtered totals | Web index | API index may include meta | MISSING UI |

**Mismatch:** Flutter invoice inbox is **PARTIAL** vs Web. API itself is COMPLETE.

Quotes Flutter: search + status + outcome (`finance_api.quotes`). Payments/receipts: search + page only.

Global search: `GET /search` + Flutter `/search`.

---

## 23. Attachments

| Document | Upload | List | Download | Delete | Web | Flutter | Status |
|----------|--------|------|----------|--------|-----|---------|--------|
| Invoice | POST attachments, draft/edit permission invoices.edit | presenter | GET | DELETE | yes | yes | COMPLETE |
| Quote | none | none | none | none | no | no | MISSING |
| Contract | POST | presenter | GET | DELETE | yes | yes | COMPLETE |
| Expense | file on expense; GET attachment | | download | destroy expense | yes | download | PARTIAL (single attachment) |
| Receipt/Quote notes | n/a | | | | | | |

Storage: `SecureUpload` + disk path on `finance_invoice_attachments.file_path`.

---

## 24. Audit logging

Quote outcomes: `quote_accepted`, `quote_rejected`, `quote_converted` via `QuoteService::recordOutcomeAudit`.  
Invoice send: `invoice_sent`.  
Issue/cancel/payment: AuditLog rows loaded on invoice show (Web + API `audit` array on detail).  

Flutter invoice detail: collapsible audit (`invoice_detail_widgets.dart`).

**Status:** COMPLETE for major commercial events. Not a full immutable financial ledger (GL is the accounting audit).

---

## 25. Security

| Control | Evidence | Status |
|---------|----------|--------|
| Auth | Sanctum Finance AuthController login/google/logout | COMPLETE |
| Workspace isolation | global scope + binding null without workspace | COMPLETE for scoped models |
| Cross-workspace | customer_id exists() where workspace_id; invoice queries withoutGlobalScopes still filter workspace_id in services | COMPLETE intent; always audit new queries |
| Mass assignment | services whitelist attributes; API validation | COMPLETE-ish |
| File upload | `SecureUpload` | COMPLETE pattern |
| PDF access | auth + workspace + invoices.view | COMPLETE |
| Webhook | verify in job; secrets server-side | COMPLETE verify; PARTIAL unauthenticated enqueue |
| Idempotency | payments reference, webhook event, quote convert unique, billing_occurrence_key | COMPLETE |
| ZATCA secrets | not in Flutter presenter | SECURITY-RESTRICTED |
| Permission elevation | owner/admin/manager bypass | PARTIAL / P1 |
| Destructive tests | not performed | — |

Workspace A accessing Workspace B: route binding + exists-rules + service `withoutGlobalScopes()->where('workspace_id', ...)`. No destructive probe run.

---

## 26. Permissions

Seeded in `database/seeders/FoundationSeeder.php`. API map in `AuthorizesFinanceApi::financePermissionMap`.

| Action | Key | Web | API | Flutter |
|--------|-----|-----|-----|---------|
| view invoices | invoices.view | authorizeFinance | clientActor | invoicesView |
| create | invoices.create | yes | yes | invoicesCreate |
| edit | invoices.edit | yes | yes | `can('invoices.edit')` on screens (getter omitted) |
| delete draft | invoices.delete | yes | yes | invoicesDelete |
| issue | invoices.issue | yes | yes | `can('invoices.issue')` |
| send | invoices.send | yes | yes | `can('invoices.send')` |
| remind | invoices.remind | yes | yes | `can('invoices.remind')` |
| cancel | invoices.cancel | yes | yes | `can('invoices.cancel')` |
| credit/debit | invoices.credit | yes | notes.create mapped | notesCreate |
| reverse pay | invoices.reverse_payment | yes | yes | `can(...)` |
| payments view/manage | payments.view / manage | yes | checkout GET/POST | paymentsView/Manage |
| receipts | receipts.view / send | yes | yes | receiptsView |
| quotes * | quotes.view/create/edit/issue/send/accept/reject/convert/cancel/delete | yes | yes | getters for view/create/delete; others via can() |
| customers | customers.manage (no customers.view in seeder) | module page | index uses **finance.view** | customersView **mapped** |
| statements | no statements.view seed | invoices.view | invoices.view | statementsView aliased |
| reports | reports.view | yes | yes | reportsView |
| contracts | contracts.view / manage | yes | yes | contractsView/Create |
| expenses | expenses.view/create/edit | yes | delete uses expenses.edit | expensesView/Create |
| payroll | payroll.view/manage | yes | yes | payrollView/Manage |
| advances | finance.salary_advances.* | yes | yes | salaryAdvances* |
| adjustments | finance.adjustments.* | yes | yes | adjustments* |
| settings | finance.settings | yes | yes | settings |
| workspace.manage | elevates | | | |

**Mismatch:** Flutter `customersView` depends on mapped `customers.view`. Bootstrap sends the map, so list works if user has finance.view. Direct Spatie `customers.view` is **not** a seeded permission — COMPLETE via mapping, confusing for auditors.

**No Policy classes** for Finance invoices. **Status:** PARTIAL.

---

## 27. API inventory

Base: `/api/finance/v1`  
Auth: public login/google/forgot/reset; rest Sanctum + workspace.

### Auth / session

| Method | URL | Auth | Notes |
|--------|-----|------|-------|
| POST | /auth/login | public throttle | |
| POST | /auth/google, /auth/social, /auth/google/start | public | |
| GET | /auth/google/status | sanctum | |
| POST | /auth/forgot-password, /auth/reset-password | public | |
| POST | /auth/logout | sanctum | |
| GET | /auth/me | sanctum | |
| GET | /workspaces | sanctum | |
| GET | /workspaces/current | + workspace | |
| POST | /workspaces/switch | write throttle | |

### Customers

GET/POST /customers, GET/PUT /customers/{id} — finance.view for read; manage for write.

### Quotes

Full lifecycle listed in `routes/finance-api.php` 49–60.

### Sales invoices

CRUD, issue, cancel, send, remind, GET/POST checkout, payments, reverse, attachments, pdf — lines 62–78.

### Payments / receipts / statements / credit-notes / contracts / expenses / purchases / hubs / catalog / projects / price-lists / POs / leads / treasury / copilot / fiscal-years / reports / exports / settings / employees / payroll / advances / adjustments

As `routes/finance-api.php` 80–224.

### E-invoice (Phase 10 contract)

GET /invoices, /invoices/{id}, POST issue, GET xml, qr, pdf, POST cryptographic-stamp  
Same for /notes (e-invoice notes, **not** credit notes)  
POS: /pos-invoices xml/qr/stamp  

**Flutter unused.** Risk: name collision mentally with sales invoices vs e-invoice `/invoices`.

**Web-only (no 1:1 API):** merchant payment KYC pages; some Blade-only placeholders `modules/{key}`; cashbox page; POS entire stack.

**Unused by Flutter:** e-invoice XML/QR/stamp; possibly copilot depending on product use (route exists in Flutter `/copilot`).

Idempotency: not HTTP Idempotency-Key header; resource uniqueness instead.

Errors: `FinanceApiController::fail` with `ApiErrorCode`; validation 422.

---

## 28. Flutter architecture

**App:** `apps/hasim_finance`  
**State:** Riverpod (`authControllerProvider`, `financeApiProvider`, `financeCatalogProvider`)  
**Router:** `lib/core/routing/app_router.dart` GoRouter + `FinanceShell`  
**API:** `lib/core/api/finance_api.dart`  
**Models:** `lib/core/models/models.dart`  
**Permissions:** `lib/core/permissions/finance_permissions.dart`  
**Errors:** `lib/core/network/api_exception.dart` (401/403/404/409/422/429)

### Routes (billing-relevant)

`/dashboard`, `/search`, `/customers`, `/quotes`, `/invoices`, `/payments`, `/receipts`, `/statements`, `/notes`, `/contracts`, `/expenses`, `/purchases`, `/reports`, `/settings`, hubs `/sales` `/billing` `/vat` `/alerts` `/accounting` `/banks` `/treasury`, `/exports`, `/fiscal-years`, `/products`, `/inventory`, `/projects`, `/price-lists`, `/purchase-orders`, `/leads`, `/suppliers`, `/people`, `/payroll`, `/advances`, `/allowances`, `/bonuses`, `/deductions`, `/copilot`.

Invoice UI: `invoices_screens.dart` + `invoice_detail_widgets.dart`.

**Authoritative calc:** Flutter `DocumentLinesEditor` / Web Alpine `recalculate()` are **display preview**. Save strips line totals on API (`HandlesFinanceClient` unset). **SERVER AUTHORITATIVE.**

---

## 29. Web → API → Flutter flow

Same services. Presenter shapes JSON. Flutter parses money as strings via `MoneyFields.asMoney`.

Exception: Web invoice show ZATCA copy ≠ backend e-invoice generation ≠ Flutter `has_qr` placeholder.

---

## 30. Feature parity matrix

| Feature | Web | API | Flutter | Status | Missing |
|---------|-----|-----|---------|--------|---------|
| Invoice list | yes | yes | yes | PARTIAL | Flutter date/customer/project/contract/payment_method filters; pipeline totals |
| Invoice create/edit draft | yes | yes | yes | COMPLETE | Flutter always draft; Web can request issued on store |
| Walk-in customer | yes | yes | yes | COMPLETE | quotes no walk-in |
| Manual invoice number | yes if setting | yes | yes if nonempty | COMPLETE | gated by settings.allow_manual_invoice_numbers on server |
| Issue | yes | yes | yes | COMPLETE | |
| Cancel | yes | yes | yes | COMPLETE | payment guard |
| Delete draft | yes | yes | yes | COMPLETE | |
| PDF | yes | yes | yes | COMPLETE | |
| Email send | yes | yes | yes | COMPLETE | |
| Remind | yes | yes | yes | COMPLETE | |
| Record payment | yes | yes | yes | COMPLETE | |
| Reverse payment | yes | yes | yes | COMPLETE | |
| Checkout | yes | yes | yes | PARTIAL | merchant KYC WEB-ONLY |
| Attachments | yes | yes | yes | COMPLETE | quotes MISSING |
| Credit note | yes | yes | yes | COMPLETE | |
| Debit note | yes (same controller type) | type credit\|debit | NoteFormScreen dropdown | COMPLETE | Web create UX more credit-titled |
| Audit trail | yes | yes | yes | COMPLETE | |
| Deliveries | yes | yes | yes | COMPLETE | |
| Tax breakdown table | yes show | in DB; not in invoiceDetail presenter | MISSING field | PARTIAL | presenter omits tax_breakdown |
| Snapshots on detail | yes | company/recipient | yes | COMPLETE | pdf_snapshot not in Flutter model |
| ZATCA XML/QR/stamp | copy says no | /invoices/{id}/* | no client | API-ONLY / BACKEND-ONLY | Flutter + Web download |
| Quotes lifecycle | yes | yes | yes | COMPLETE | attachments MISSING |
| Payments list | yes | yes | yes | PARTIAL | thinner filters |
| Receipts | yes | yes | yes | COMPLETE | |
| Statements | index+show | GET show required customer+dates | StatementScreen | PARTIAL | Flutter index thinner |
| Contracts + schedules | yes | yes | yes | COMPLETE | |
| Expenses | yes | yes | yes | PARTIAL | recurring UI |
| Purchases | module + API | yes | yes | PARTIAL | Web purchases page vs full form |
| Suppliers | yes | yes | yes | COMPLETE | |
| Reports ledger | yes | yes | yes | COMPLETE | |
| Dashboard | yes | yes | yes | PARTIAL | draft inclusion in sums |
| People/payroll/advances | yes | yes | yes | COMPLETE | |
| Settings tax/treasury/logo | yes | yes | yes | COMPLETE | |
| Fiscal years | yes | yes | yes | COMPLETE | |
| Treasury reconcile | yes | yes | yes | COMPLETE | |
| Price lists | yes | yes | yes | COMPLETE | |
| POs | yes | yes | yes | COMPLETE | |
| Leads | yes | yes | yes | COMPLETE | CRM-lite |
| Copilot | yes | yes | yes | PARTIAL | quality not audited |
| Exports CSV | yes | /exports/{dataset} | ExportsScreen | COMPLETE | |
| POS invoices | pos.* | /pos-invoices | no | NOT-FINANCE | |

Do not mark COMPLETE merely because a route exists. Rows marked COMPLETE called the same services.

---

## 31. Field parity matrix

Legend: Y = present, N = absent, P = partial.

### Customer

| Field | Web | API | Flutter | Issue |
|-------|-----|-----|---------|-------|
| name | Y | Y | Y | |
| party_type | Y | Y | Y | |
| email/phone/whatsapp | Y | Y | Y | |
| vat_number | Y | Y | Y | |
| commercial_registration | Y | Y | Y | |
| address parts | Y | Y | Y | |
| payment_terms | Y | Y | Y | |
| notes | Y | Y | Y | |
| outstanding_balance | computed | Y | Y | |
| balance cache | column | N presenter | N | BACKEND-ONLY cache |

### Quote

| Field | Web | API | Flutter | Issue |
|-------|-----|-----|---------|-------|
| quote_number | Y | Y | Y | |
| customer_id | required | required | required | no walk-in |
| dates, currency, tax, totals | Y | Y | Y | server calc |
| notes, terms | Y | Y | Y | |
| status, outcome | Y | Y | Y | |
| rejection_reason | Y | Y | Y | |
| converted_invoice_* | Y | Y | Y | |
| deliveries | Y | Y | Y | |
| attachments | N | N | N | MISSING |
| lines + unit/exemption | Y | Y | LineItem | |

### Invoice

| Field | Web | API summary | API detail | Flutter list | Flutter detail | Issue |
|-------|-----|-------------|------------|--------------|----------------|-------|
| invoice_number | Y | Y | Y | Y | Y | |
| type | Y | Y | Y | sales list | Y | |
| customer_id/name | Y | Y | Y | Y | Y | |
| supplier_* | Y | Y | Y | purchases app | Y | |
| issue/due | Y | Y | Y | due in subtitle | Y | |
| supply_date | column | N | N | N | N | PARTIAL |
| issued_at | Y | N | N | N | N | PARTIAL |
| currency | Y | Y | Y | Y | Y | |
| money columns | Y | Y | Y | amountDue | all | |
| tax_profile/rate/mode | Y | detail | Y | N list | Y | |
| tax_breakdown | Y | N | N | N | N | PARTIAL |
| tax_document_subtype | Y | zatca | zatca | N | zatcaSubtype | |
| zatca_requirement | Y | zatca | zatca | N | Y | |
| has_qr | stale copy | has_qr placeholder | same | N | hasZatcaQr | PARTIAL/BROKEN vs e-invoice QR |
| notes, payment_terms | Y | detail | Y | N | Y | |
| contract_id, project_id | Y | detail | Y | N | ids only, names via nav | PARTIAL names |
| lines | Y | | Y | N | Y | |
| payments/receipts/notes/deliveries/checkout/audit/attachments | Y | | Y | N | Y | |
| company/recipient snapshot | Y | | Y | N | Y | |

### Invoice item

| Field | Web form | API item | Flutter LineItem |
|-------|----------|----------|------------------|
| product_id nullable | Y | Y | Y |
| product_name/description | Y | Y | Y |
| unit | Y | Y | Y |
| qty/price/discount | Y | Y | Y |
| tax_rate/profile | Y | Y | Y |
| exemption_reason/code | Y | Y | Y (code not in toPayload) |
| tax_amount/taxable/total | preview JS | server | display only |

### Payment / Receipt / Note / Contract / Expense / Supplier / Product / Person / Salary / Advance

Flutter models include the operational fields used by presenters (see `models.dart` PaymentRecord, ReceiptRecord, NoteRecord, ContractRecord, ExpenseRecord, SalaryAdvanceRecord). Gaps: receipt deliveries on Flutter ReceiptRecord exist; note PDF via API; expense single attachment; employee remaining/net on payroll records.

**Status:** Field parity **PARTIAL** mainly invoice tax_breakdown, issued_at, supply_date, true QR, quote attachments.

---

## 32. Invoice form parity

**Web:** `resources/views/workspace/finance/invoices/create.blade.php` + `InvoiceController::create/store/edit/update`  
**API:** `SalesInvoiceController::invoicePayload`  
**Flutter:** `InvoiceFormScreen` (`invoices_screens.dart` ~476+)

| Field / behavior | Web | API | Flutter | Validation | Default | Notes |
|------------------|-----|-----|---------|------------|---------|-------|
| type sales/purchase | select | payload type forced sales on this controller | sales form; purchases separate | in:sales,purchase | sales | COMPLETE split apps |
| tax_document_subtype | select | in:standard,simplified | dropdown | | standard | COMPLETE |
| zatca_requirement | select | in:not_required,required | dropdown | | not_required | internal flag |
| invoice_number | if allowed | nullable max 64 | text if nonempty | unique ws | auto | COMPLETE |
| customer_id | select | exists workspace | CustomerSelect | sales requires id or name | | COMPLETE |
| walk-in customer_name | input | max 255 | switch + name | | | COMPLETE |
| supplier | purchase | purchase controller | PurchaseFormScreen | required purchase | | COMPLETE |
| issue_date | required | required date | date field | | today | COMPLETE |
| due_date | optional | after_or_equal issue | optional | | | COMPLETE |
| payment_terms | text | max 255 | text | | | COMPLETE |
| currency | text size 3 | size 3 | field | | SAR | COMPLETE |
| contract_id | select | exists contracts | dropdown | | | COMPLETE |
| project_id | select | exists finance_projects | dropdown | | | COMPLETE |
| tax_profile_type | select | enum | from catalog | | standard | COMPLETE |
| tax_rate | number 0–100 | numeric | | | settings VAT | COMPLETE |
| tax_price_mode | exclusive/inclusive | enum | | | exclusive | COMPLETE |
| notes | textarea | string | | | | COMPLETE |
| lines product/free-text | Alpine | items array; client totals stripped | DocumentLinesEditor | ≥1 item in service | | COMPLETE |
| qty min | 0.001 UI | server max(0) tax engine | | | 1 | COMPLETE |
| attachments on create | storeUploadedAttachments | multipart on store/edit | upload after save on detail | | | PARTIAL Flutter: upload on detail not create |
| status | can issue on create | invoice_status draft\|issued | **always draft** | | draft | PARTIAL UX |
| preview totals | Alpine recalculate | ignored | no local authoritative total | | | CLIENT DISPLAY ONLY |

**Status:** COMPLETE field coverage for sales draft. PARTIAL: Flutter no issue-on-create; attachments after save.

---

## 33. Invoice details parity

**Web:** `show.blade.php`  
**Flutter:** `InvoiceDetailScreen` + `invoice_detail_widgets.dart`  
**API:** `invoiceDetail`

| Block | Web | API | Flutter | Status |
|-------|-----|-----|---------|--------|
| number + recipient | Y | Y | header widget | COMPLETE |
| document + payment badges | Y | Y | Y | COMPLETE |
| due today | Y | derived | check widgets | PARTIAL |
| type sales/purchase | Y | Y | Y | COMPLETE |
| PDF / send / remind / issue / cancel | Y | Y | Y | COMPLETE |
| checkout | Y | Y | Y | COMPLETE |
| issued_at | Y | N | N | PARTIAL |
| tax subtype + zatca flag | Y | zatca | Y | COMPLETE flags |
| totals + credited/debited | Y | Y | Y | COMPLETE |
| contract/project links | Y | ids | customer nav; contract id | PARTIAL |
| customer snapshot vs live | Y | snapshots | snapshots | COMPLETE |
| ZATCA card | stale “not generated” | has_qr placeholder | hasZatcaQr | BROKEN copy / PARTIAL data |
| notes | in ZATCA card | notes | notes widget | COMPLETE |
| items table + unit + exemption | Y | Y | items table | COMPLETE |
| tax breakdown by category | Y | N | N | PARTIAL |
| payments + reverse | Y | Y | Y | COMPLETE |
| receipts | Y | Y | Y | COMPLETE |
| credit notes | Y | Y | Y + nav | COMPLETE |
| attachments | Y | Y | AttachmentsCard | COMPLETE |
| deliveries | Y | Y | Y | COMPLETE |
| audit | Y | Y | collapsible | COMPLETE |
| journal entries | Web may show | not in presenter | N | WEB-ONLY |

---

## 34. Quote parity

See §7 and §30. **COMPLETE** except attachments and walk-in. Convert → draft invoice **COMPLETE** and duplicate-safe.

---

## 35. Payment parity

Manual + reverse + list **COMPLETE**. Checkout **COMPLETE** settle path. Merchant eligibility **WEB-ONLY**. Flutter payment list filters **PARTIAL**.

---

## 36. Receipt parity

**COMPLETE** Web/API/Flutter for list/show/pdf/send. Creation is payment side-effect.

---

## 37. Statement parity

`CustomerStatementService::build` + `GET /statements` requires customer_id, from, to, format json|csv|pdf.  
Web index + show. Flutter `StatementScreen` query param customer_id.  

**PARTIAL:** Flutter has no rich customer-picker index matching Web `statements.index`. PDF uses same Blade as Web.

---

## 38. Contract parity

**COMPLETE** for CRUD, status, PDF, attachments, billing schedules generate/pause/cancel/activate on all three tiers.

---

## 39–41. People / salary / advance parity

**Classification:** Finance obligations of the **workspace company**, not HASEM HR, not attendance/leave.

| Area | Web | API | Flutter | Status |
|------|-----|-----|---------|--------|
| Employees | finance/employees | /employees CRUD + payroll-records | /people | COMPLETE |
| Payroll overview | payroll.index | GET /payroll | /payroll | COMPLETE |
| Advances | salary-advances | /salary-advances + repay | /advances | COMPLETE |
| Allowances/bonuses/deductions | payroll-adjustments | /payroll-adjustments approve/post/cancel | /allowances /bonuses /deductions | COMPLETE |

Who is owed: `finance_employees`. Amounts/remaining on payroll records and advances (`remaining_amount`, `settled_amount`). GL types include `payroll` on journal enum.

Attendance/leave/recruitment: **NOT-FINANCE** (not this module).

---

## 42. Edge cases

| Case | Behavior | Status |
|------|----------|--------|
| Zero-value invoice | tax engine allows 0; due 0 → paid when issued | COMPLETE / product-legal question |
| Negative qty/price | tax calculateLine max(0); strict document validation in calculateDocument | PARTIAL (permissive vs strict paths) |
| Large amounts | decimal(14,2) | COMPLETE storage |
| Decimals | Money cents HALF_UP scale 2; qty 3dp | COMPLETE |
| Tax-exempt | tax_profile_type exempt / zero_rated / out_of_scope | COMPLETE |
| No customer | sales rejected unless walk-in name | COMPLETE |
| Walk-in | customer_name; no AR rollup | COMPLETE |
| Deleted customer | invoices customer_id nullOnDelete; quotes restrictOnDelete | COMPLETE |
| Deleted product | item product_id nullOnDelete; name snapshot on line | COMPLETE |
| Free-text item | product_id null | COMPLETE |
| Cancel with payments | blocked | COMPLETE |
| Already paid | payment refused | COMPLETE |
| Partial | payment_status partial | COMPLETE |
| Overdue | due_date + hourly refresh | COMPLETE |
| Duplicate payment reference | return existing | COMPLETE |
| Duplicate checkout | reuse pending if amount matches | COMPLETE |
| Duplicate webhook | WebhookEvent firstOrCreate | COMPLETE |
| Duplicate quote convert | unique + early return | COMPLETE |
| Amount change after checkout | no settle if checkout > due | COMPLETE refuse |
| Cross-workspace | scoped + exists rules | COMPLETE intent |
| Expired quote | cannot accept/convert | COMPLETE |
| Rejected quote | not convertible | COMPLETE |
| Missing attachment | 404 | COMPLETE |
| Failed email | delivery failed + error | COMPLETE |
| Missing PDF engine | StatementController throws if DomPDF missing | PARTIAL env |
| Currency mismatch webhook | no settle | COMPLETE |

---

## 43. Error handling

| Code | Laravel | Flutter |
|------|---------|---------|
| 401 | fail Unauthenticated | ApiException.isUnauthorized → login redirect via router |
| 403 | workspace/permission | isForbidden; screens `allowed:` hide |
| 404 | model binding / abort | isNotFound |
| 409 | rare; unique wrapped as RuntimeException often 500/422 | isConflict unused often |
| 422 | ValidationException | showFormError |
| 500 | RuntimeException messages (Arabic) | snackbar message |
| Network | | Dio errors |
| Payment | RuntimeException already paid / not issued | form error |
| Email/PDF | delivery failed / exception | user-visible message |
| Webhook | 202 always; failures in job | n/a |

RuntimeException from services may not always map to 422 — **PARTIAL** API error taxonomy.

---

## 44. Test coverage

**Not executed** (audit-only).

### Laravel Finance (sample)

`tests/Feature/Feature/Finance/`:

- FinancePhaseAFoundationTest, BQuotes, CSendQuote, DQuoteOutcome, ESendInvoice  
- FinanceInvoiceCheckoutTest  
- FinanceFlutterClientApiTest, Auth, DataParity, FeatureParity, PeopleObligationsApi  
- FinanceWebCompletionTest, FinanceFrontendExperienceTest  
- Phase5EInvoiceDomain, Phase7SecurityChain, Phase8ZatcaQrFoundation  

Also: `tests/Unit/EInvoicing/*`, `tests/Unit/PaymentWebhookSecurityTest`, `WebhookReplayProtectionTest`, `WebhookVerificationTest`.

### Flutter

`apps/hasim_finance/test/`: finance_screens_test, field_coverage_test, feature_parity_screens_test, data_parity_screens_test, people_screens_test, sidebar_nav_test, visual_qa_test, models_permissions_test, auth_*.

**Gaps:** no live Stripe/HyperPay; production stamp expected to fail; dashboard draft-KPI; Flutter filter mismatch not necessarily asserted as Web inbox parity.

**Status:** PARTIAL but substantial for quotes/invoices/checkout/e-invoice foundation/Flutter contracts.

---

## 45. Code quality / architecture

**Strengths:** clear InvoiceService/QuoteService/PaymentService boundaries; Money integer cents; client totals stripped; checkout does not imply paid; quote convert does not auto-issue; workspace scoping.

**Debt:**

- Dual status columns (legacy `status` + split).  
- Placeholder `zatca_*` on invoices vs e-invoice tables.  
- Web ZATCA copy stale.  
- Elevated roles bypass permission keys.  
- Services skip HTTP auth (jobs) — correct but easy to misuse.  
- POS tax engine duplicated (`PosTaxCalculator`).  
- `customers.balance` cache vs outstanding.  
- FinanceApi `/invoices` vs `/sales-invoices` naming.  
- Alpine/Flutter preview calculators duplicate tax UX (not authority).  
- TODOs/placeholders: production stamp `never`; zatca_integration_mode unused as live client.

**Coupling:**

- POS: e-invoice factory, shared Payment webhook, optional order.finance_invoice_id.  
- Booking: payment links, not InvoiceService.  
- Inbox: email_logs FK on deliveries.  
- Platform HR invitations ≠ finance employees.

---

## 46. Technical debt (priority tagged in §54)

See stale ZATCA UI, placeholder columns, permission elevation, invoice list filter gap, dashboard draft sums, webhook 202 enqueue, quote attachments absence, tax_breakdown omitted from presenter.

---

## 47–53. Missing / broken / partial / web-only / backend-only / flutter-only / security

### COMPLETE (core)

Invoice draft/issue/cancel/pay/reverse/PDF/email/remind; quote lifecycle+convert-once; receipts; credit/debit notes API+Flutter; contracts+schedules; statements engine; checkout create/reuse; webhook idempotency; Money/tax server authority; workspace isolation; Flutter invoice detail redesign consuming presenter.

### PARTIAL

Flutter invoice filters; tax_breakdown/issued_at/supply_date; ZATCA has_qr vs real QR; dashboard sums; reminder cadence configurability; expense recurring; Web purchases module page; error mapping RuntimeException; merchant eligibility in Flutter; presenter omits journal lines.

### MISSING

ZATCA FATOORA clearance/reporting; production signer; quote attachments; seeded `customers.view` / `statements.view` as real keys; HTTP Idempotency-Key; Flutter e-invoice XML/QR download; walk-in quotes.

### BROKEN

Web invoice show text claiming QR/XML are not generated while `InvoiceIssueService` generates foundation artifacts. `has_qr` tied to placeholder column.

### WEB-ONLY

Merchant KYC (`payments/merchant`); some accounting dashboard chrome; cashbox placeholder; journal entries on invoice show (if present); Alpine preview; POS UI.

### BACKEND-ONLY

E-invoice security records, certificates, stamp service, hash chain, scheduled billing/reminders, GL posting, webhook verification, CustomerBalanceService, tax engine.

### FLUTTER-ONLY

None material for billing (no client-only ledger). Visual chrome/widgets only.

### SECURITY-RESTRICTED

Payment provider secrets (`config/payment.php`), webhook secrets, ZATCA/CSID private keys, Sanctum tokens (device), stamp material. Flutter should only receive issued XML/QR **payloads**, never keys.

### NOT-FINANCE

POS cashier invoices/tax; appointments payment links; WhatsApp/Instagram/Messenger; workspace employee invitations; storefront Order payment settle.

---

## 54. Recommended priorities (no implementation in this audit)

### P0 — correctness of money / settlement / compliance claims

1. Treat `CustomerBalanceService::outstanding` as AR truth; never `customers.balance` for decisions.  
2. Keep checkout URL ≠ paid (already implemented — do not regress).  
3. Keep quote convert → **draft** invoice (already implemented — do not auto-issue without product decision).  
4. Fix **false UI claim** that ZATCA QR/XML are not generated, or stop generating them — currently contradictory.  
5. Do not expose ZATCA keys or payment secrets to Flutter.

### P1 — major Finance gaps

1. Flutter invoice inbox filters (from/to, customer, project, contract, payment_method) already supported by `InvoiceInboxService`.  
2. Presenter: expose tax_breakdown, issued_at, real QR availability from e-invoice tables (not placeholder `zatca_qr_code`).  
3. Safe Flutter/Web download of issued XML/QR **without** secrets.  
4. Dashboard metrics: exclude draft/cancelled from “sales”.  
5. Permission elevation documentation / tighter manager bypass.  
6. Quote attachments or explicit product “none”.  
7. Webhook settle when checkout amount > due: define operator recovery (now silent return).

### P2 — UX / functionality

1. Flutter statement index parity with Web.  
2. Issue-on-create optional in Flutter.  
3. Attachments on invoice create, not only detail.  
4. Contract/project names on invoice detail.  
5. Unauthenticated webhook job enqueue throttling.  
6. Map RuntimeException to 422 consistently.  
7. Debit-note labeling on Web.

### P3 — polish

1. Remove unused zatca_* invoice columns or backfill from e-invoice.  
2. Seed `customers.view` / `statements.view` real keys.  
3. Unify `/invoices` vs `/sales-invoices` naming in docs.  
4. Alpine preview vs server rounding differences.  
5. Copilot quality.  
6. Recurring expense UI.

---

## 38. Final executive answer (required)

### How complete is our Billing system?

**Core AR billing is complete on Laravel and usable on Flutter.** Tax, numbering, issue, GL, payments, receipts, reversals, quote conversion, PDFs, and email are real server workflows. Flutter is a fairly complete **client**, not a second ledger. ZATCA is a **foundation**, not FATOORA integration. Inbox filters and compliance artifacts are the main product gaps.

| Area | Assessment |
|------|------------|
| Laravel Backend | **86/100 COMPLETE** for operational billing; ZATCA Phase 2 MISSING |
| Laravel Web | **82/100** — full forms/actions; ZATCA show copy stale |
| Finance API | **88/100** — sales-invoices + quotes + payments + e-invoice extras |
| Flutter Finance | **74/100** — actions wired; filters/compliance artifacts PARTIAL |
| Web → API parity | **90/100 COMPLETE** for billed operations |
| API → Flutter parity | **76/100 PARTIAL** |
| Feature parity | **78/100** |
| Field parity | **80/100** |
| Invoice parity | **82/100** |
| Payment parity | **85/100** |
| Quote parity | **84/100** (no attachments) |
| ZATCA | **45/100 BACKEND-ONLY foundation; SECURITY-RESTRICTED keys** |
| PDF | **88/100 COMPLETE** |
| Email | **85/100 COMPLETE** |
| Checkout | **80/100 COMPLETE settle; WEB-ONLY merchant KYC** |
| Reports | **80/100 COMPLETE ledger set** |
| Security | **78/100** workspace COMPLETE; elevation PARTIAL |
| Permissions | **72/100** keys+controller; no policies; mapped aliases |
| Testing | **70/100** strong feature tests; live payments untested here |

### WHAT IS COMPLETE

Server-authoritative invoices/quotes/tax/GL/payments/receipts/credit-debit/statements engine/contracts schedules/PDF/email/remind/checkout+idempotent webhook/Flutter CRUD+lifecycle actions/people-payroll-advances.

### WHAT IS PARTIAL

Flutter filters; ZATCA product exposure; dashboard KPI composition; presenter field omissions; merchant onboarding; error taxonomy; reminder configurability.

### WHAT IS MISSING

FATOORA clearance/reporting; production crypto stamp; quote attachments; walk-in quotes; dedicated customers.view seed key.

### WHAT IS BROKEN

Web invoice details ZATCA copy vs `InvoiceIssueService`; `has_qr` placeholder vs e-invoice QR store.

### WHAT IS WEB-ONLY

Merchant KYC; some Blade-only modules/cashbox; POS; possibly invoice GL chrome.

### WHAT IS BACKEND-ONLY

Tax engine, GL, e-invoice XML/hash/stamp internals, webhook verify, schedulers, balance service.

### WHAT IS SECURITY-RESTRICTED

Provider secrets, webhook secrets, ZATCA private keys/certificates, stamp private material, personal access tokens.

### WHAT IS NOT-FINANCE

POS cashier invoices; appointments; messaging channels; workspace invitation “employees”; storefront orders.

### Roadmap

**P0** — money truth, checkout≠paid, convert≠auto-issue, fix ZATCA contradiction, never leak secrets.  
**P1** — Flutter inbox filters, real QR/XML download, dashboard draft exclusion, quote attachments decision, webhook under/over due recovery.  
**P2** — statement index, issue-on-create, create-time attachments, 422 mapping, webhook throttle.  
**P3** — naming, seed keys, preview rounding, placeholders cleanup.

---

## Appendix A — Traceability index

| Topic | Path |
|-------|------|
| Finance API routes | `routes/finance-api.php` |
| API mount | `routes/api.php` prefix finance/v1 |
| Web finance routes | `routes/web.php` ~355–516 |
| Webhook | `routes/api.php` POST `/webhooks/payments/{provider}` |
| Schedule | `routes/console.php` |
| InvoiceService | `app/Services/Finance/InvoiceService.php` create/issue/cancel/updateDraft/deleteDraft/syncPaymentStatus |
| InvoiceStateService | `app/Services/Finance/InvoiceStateService.php` |
| TaxCalculationService | `app/Services/Finance/Tax/TaxCalculationService.php` |
| Money | `app/Support/Money/Money.php` |
| InvoicePaymentService | `app/Services/Finance/InvoicePaymentService.php` |
| InvoiceCheckoutService | `app/Services/Finance/InvoiceCheckoutService.php` |
| PaymentService webhook | `app/Services/Payment/PaymentService.php` processWebhook, settleFinanceInvoicePayment |
| QuoteService | `app/Services/Finance/QuoteService.php` |
| CustomerBalanceService | `app/Services/Finance/CustomerBalanceService.php` |
| SalesInvoiceController | `app/Http/Controllers/Api/Finance/V1/SalesInvoiceController.php` |
| Web InvoiceController | `app/Http/Controllers/Workspace/Finance/InvoiceController.php` |
| Presenter | `app/Services/Finance/Api/FinanceClientPresenter.php` invoiceDetail |
| Permissions map | `app/Http/Controllers/Api/Finance/Concerns/AuthorizesFinanceApi.php` |
| Seeder keys | `database/seeders/FoundationSeeder.php` |
| Flutter API | `apps/hasim_finance/lib/core/api/finance_api.dart` |
| Flutter models | `apps/hasim_finance/lib/core/models/models.dart` |
| Flutter invoice UI | `apps/hasim_finance/lib/features/invoices/invoices_screens.dart` |
| Flutter invoice detail | `apps/hasim_finance/lib/features/invoices/invoice_detail_widgets.dart` |
| Flutter router | `apps/hasim_finance/lib/core/routing/app_router.dart` |
| Web form | `resources/views/workspace/finance/invoices/create.blade.php` |
| Web show | `resources/views/workspace/finance/invoices/show.blade.php` |
| E-invoice issue | `app/Services/EInvoicing/InvoiceIssueService.php` |
| Strip client totals | `app/Http/Controllers/Api/Finance/Concerns/HandlesFinanceClient.php` |

---

## Appendix B — Method → HTTP cheat sheet

| Operation | Web name | API |
|-----------|----------|-----|
| List invoices | workspace.finance.invoices.index | GET /sales-invoices |
| Create | invoices.store | POST /sales-invoices |
| Show | invoices.show | GET /sales-invoices/{id} |
| Update draft | invoices.update | PUT /sales-invoices/{id} |
| Delete draft | invoices.destroy | DELETE /sales-invoices/{id} |
| Issue | invoices.issue | POST .../issue |
| Cancel | invoices.cancel | POST .../cancel |
| Send | invoices.send | POST .../send |
| Remind | invoices.remind | POST .../remind |
| PDF | invoices.pdf | GET .../pdf |
| Pay | invoices.payments.store | POST .../payments |
| Reverse | invoices.payments.reverse | POST .../payments/{id}/reverse |
| Checkout | invoices.checkout | GET/POST .../checkout |
| Attachments | invoices.attachments.* | POST/GET/DELETE .../attachments |
| Convert quote | quotes.convert | POST /quotes/{id}/convert |

---

*End of forensic audit. No application code was modified.*
