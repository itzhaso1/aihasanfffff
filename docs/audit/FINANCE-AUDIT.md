# FINANCE-AUDIT

Audit date: 2026-09-13  
This is the most important money domain. No journals were posted in this audit.

---

## 1. What the system actually is

**Hybrid: invoicing + a real double-entry core**, not a full IFRS accounting suite.

| Capability | Status | Evidence |
| --- | --- | --- |
| Chart of accounts | Implemented | `ChartOfAccountsService`, `FinanceAccount` |
| Journals (draft → posted, reverse) | Implemented | `AccountingService`, immutable posted rows |
| Period / year close blocks posting | Implemented | `FinancialPeriodGuardService` |
| Trial balance / P&L / BS / GL | Implemented (reports) | `LedgerReportService` |
| Cash flow | Partial | Movement of accounts 1000/1100 — not IAS 7 statement |
| Bank reconciliation | Partial | Match only; no auto JE; no CSV/OFX |
| Multi-currency FX revaluation | Missing | Accounts 4200/5400 reserved |
| Invoices + items + tax + payments + reverse | Implemented | `InvoiceService`, `TaxCalculationService`, `Money` |
| Quotes | Implemented | models + `/api/finance/v1/quotes` (older spec text is stale) |
| Credit notes | Implemented | `FinanceCreditNote*` |
| Receipts + PDF/email | Implemented | `ReceiptService` |
| Expenses + reversal on delete | Implemented | `ExpenseService` (repair audit) |
| Payroll / advances | Implemented | finance employee tables |
| Purchase orders → bill | Implemented | `PurchaseOrderService` |
| Recurring billing schedules | Implemented | unique occurrence key |
| Projects profitability | Partial | invoice − expense sums |
| Dashboard KPIs | Partial / divergent | Cards may ≠ ledger P&L (`docs/HASEM_IMPLEMENTATION_STATUS.md`) |
| Official e-invoicing (ZATCA) | Foundation only | XML/QR/stamp harness — **not certified** |

---

## 2. Money math

- Canonical helper: `App\Support\Money\Money` (integer minor units).
- Invoice/tax/treasury/accounting services are specified to use it.
- **Leak:** `Money::round()` returns `float`. POS and reports call it often (`PosTaxCalculator`, `PosOrderService` sums).
- DB columns remain `decimal`. PHP float must not become the writer of record.

POS has a **second** tax engine: `PosTaxCalculator` — exclusive workspace rate, and **client-supplied line tax is kept when present** (`attachLineTax`).

Cashier Flutter has `lib/core/pos/domain/pricing_service.dart` and `local_finance_repository.dart` — local totals for offline. This can diverge from Laravel `InvoiceService` if sync posts client numbers.

---

## 3. Integrity risks

### AUD-FIN-001
- Category: Finance
- Severity: Critical
- Title: Dashboard / KPI formulas can disagree with ledger P&L
- Location: `PeriodComparisonService`, finance dashboard views, `docs/HASEM_IMPLEMENTATION_STATUS.md` honesty #1
- Evidence: Spec: “Dashboard metric cards are still a simplified formula; use ledger P&L for accounting close.”
- Impact: Owner makes cash/tax decisions on the wrong number
- Exploitability: n/a (integrity)
- Recommendation: Dashboard cards read ledger aggregates only
- Effort: M
- Priority: P0

### AUD-FIN-002
- Category: Finance
- Severity: Critical
- Title: POS / offline clients can supply tax amounts
- Location: `PosTaxCalculator::attachLineTax`, `PosOrderService` snapshot `tax_amount`
- Evidence: “Client-supplied line tax is kept when present.”
- Impact: Under/over tax, wrong VAT payable, ZATCA mismatch later
- Recommendation: Server always recomputes; treat client tax as hint only
- Effort: M
- Priority: P0

### AUD-FIN-003
- Category: Finance
- Severity: High
- Title: Two tax engines
- Location: `app/Services/Finance/Tax/*` vs `app/Services/Pos/PosTaxCalculator.php`
- Impact: Same cart totals differently on finance vs POS
- Recommendation: One `TaxCalculationService` for all issued documents
- Effort: L
- Priority: P1

### AUD-FIN-004
- Category: Finance
- Severity: High
- Title: Elevated managers can perform finance mutations
- Location: `AuthorizesFinanceApi::financePermissionMap` ORs `$elevated` into every key
- Impact: Privilege = financial loss (issue/cancel/reverse)
- Recommendation: Elevation for view + issue maybe; not delete/reverse/settings
- Effort: M
- Priority: P0

### AUD-FIN-005
- Category: Finance
- Severity: High
- Title: Concurrent payments / stock races not proven on MySQL
- Location: invoice TX + `TreasuryBalanceService::lockForUpdate`; docs: SQLite tests ≠ InnoDB
- Impact: Double payment, negative stock, wrong AR
- Recommendation: MySQL isolation tests; unique payment idempotency keys
- Effort: L
- Priority: P1

### AUD-FIN-006
- Category: Finance
- Severity: Medium
- Title: Integrity artisan uses float sums
- Location: `routes/console.php` `finance:invoices:integrity-report`
- Impact: False confidence in reconciliation
- Recommendation: Integer minor-unit sums
- Effort: S
- Priority: P2

### AUD-FIN-007
- Category: Finance
- Severity: Medium
- Title: Copilot is not an accountant
- Location: `FinanceCopilotService` (keyword/SQL aggregates)
- Impact: Users trust a projection labeled as insight
- Recommendation: Keep read-only; never auto-post (already a platform rule)
- Effort: S
- Priority: P2

---

## 4. TAX / ZATCA readiness (do not claim compliant)

| Item | Classification | Evidence |
| --- | --- | --- |
| VAT rates / tax number fields | Partial | Tax engine + settings + legal placeholder `[الرقم الضريبي]` |
| Invoice numbering | Implemented | workspace-unique patterns in invoice domain |
| QR | Partial (foundation) | `app/EInvoicing/QR`, Phase8 tests |
| XML / UBL mapping | Partial | `app/EInvoicing/Xml`, mapping exceptions |
| UUID / snapshots | Partial | `IssuedDocumentSnapshot`, e_invoice documents |
| Cryptographic stamp | Partial / harness | Phase9 tests; production profile throws `ProductionCryptographicProfileException` |
| Credit / debit notes | Partial | Credit notes exist; debit notes not first-class in models list |
| Simplified vs standard invoices | Partial | `InvoiceTypeCatalogTest` |
| Official clearance / reporting | Missing | Spec: “Do not invent ZATCA claims” |
| JoFotara / AE | Missing | Country profiles only |

**Not compatible / not certified.** Foundation code + unit harnesses only.

---

## 5. What must not be broken

- `AccountingService` draft-then-post + `Money::cmp` balance check
- Posted journal immutability + reverse-only corrections
- Period guard
- `InvoiceService` workspace checks on customer/supplier
- Integer money in tax/invoice issue path
- Billing occurrence unique key
- Expense delete → reversal (not erase)
- Finance Flutter **must not** grow local tax/GL math

UNKNOWN — Requires runtime verification: a full sale→payment→TB→P&L on MySQL with concurrent cashiers.
