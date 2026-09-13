# Finance Web → Flutter feature inventory

Source of truth: Laravel Finance Web (`resources/views/workspace/finance`, `routes/web.php` Finance group, Workspace Finance controllers).

Client: `apps/hasim_finance` consuming `/api/finance/v1`. Sales invoices stay on `/sales-invoices` (not Phase 10 `/invoices`).

Classification:

- **A — MUST EXIST IN FLUTTER**: genuine Finance user-facing product feature.
- **B — WEB-ONLY BY DESIGN**: architectural, security, or product-boundary reason (not “too hard”).
- **C — NOT FINANCE**: belongs to another HASEM product or is not a Finance user feature.

## Navigation (Web sidebar)

Web sidebar: `resources/views/workspace/finance/partials/sidebar.blade.php`.

| Web section | Web item | Class | Flutter |
|---|---|---|---|
| لوحة التحكم | لوحة القرار | A | `/dashboard` |
| لوحة التحكم | لوحة الفوترة | A | `/billing` |
| المبيعات | المبيعات | A | `/sales` (Web `SalesController` is a real hub; placeholder `ModulePageController::sales` is unused by the named route) |
| المبيعات | عروض الأسعار | A | `/quotes` |
| المبيعات | الفواتير | A | `/invoices` |
| المبيعات | الدفعات | A | `/payments` |
| المبيعات | الإيصالات | A | `/receipts` |
| المبيعات | كشف حساب العميل | A | `/statements` |
| المبيعات | العملاء المحتملون | A | `/leads` |
| المبيعات | العقود | A | `/contracts` |
| المبيعات | العملاء | A | `/customers` |
| المبيعات | قوائم الأسعار | A | `/price-lists` |
| المشتريات والموردون | فواتير الشراء | A | `/purchases` (dedicated purchase invoices, not mixed sales inbox) |
| المشتريات والموردون | أوامر الشراء | A | `/purchase-orders` |
| المشتريات والموردون | الموردون | A | `/suppliers` |
| المصروفات والمخزون | المصروفات | A | `/expenses` |
| المصروفات والمخزون | المنتجات | A | `/products` (Finance read view of catalog + sales totals) |
| المصروفات والمخزون | المخزون | A | `/inventory` (movement list belonging to Finance) |
| المصروفات والمخزون | المشاريع | A | `/projects` |
| المحاسبة والضرائب | لوحة المحاسبة | A | `/accounting` (read trial balance / accounts / recent entries) |
| المحاسبة والضرائب | السنوات والفترات | A | `/fiscal-years` |
| المحاسبة والضرائب | VAT | A | `/vat` |
| المحاسبة والضرائب | التقارير | A | `/reports` |
| المحاسبة والضرائب | تصدير CSV | A | `/exports` |
| المحاسبة والضرائب | التنبيهات | A | `/alerts` |
| المحاسبة والضرائب | المساعد المالي | A | `/copilot` (thin client over `FinanceCopilotService`) |
| الرواتب والبنوك | الرواتب / البدلات / الخصومات / المكافآت / السلف / موظفو المالية | C | Not copied. HR/payroll product surface living under the Finance Web shell. |
| الرواتب والبنوك | الحسابات البنكية | A | `/banks` |
| الرواتب والبنوك | الخزينة والتسويات | A | `/treasury` (accounts, transfers, bank statements, matching) |
| الإعدادات | إعدادات الفوترة | A | `/settings` |
| Extra in Flutter | الإشعارات الدائنة/المدينة | A | `/notes` — Web nests credit notes under invoice show; Flutter also lists them. |
| Extra in Flutter | بحث مالي | A | `/search` — Web has `workspace.finance.search`, not in the sidebar. |
| Not in sidebar | الصندوق `cashbox` | B | Web placeholder only (`ModulePageController::cashbox`). Treasury covers cash accounts. |
| Not in sidebar | Accounting settings placeholder | B | Web placeholder `settings-accounting`. Company finance settings are `/settings`. |

Flutter navigation is grouped to match Web (RTL sidebar on desktop ≥980px; 3 tabs + grouped more-sheet on smaller screens). Payroll is omitted on purpose.

## Module inventory

### 1. Decision dashboard

Web: `DashboardController` + `FinanceAnalyticsService` (date/customer/product/project/lifecycle/payment-method filters, hero KPIs, attention, top customers, series).

API: `GET /dashboard` returns KPI cards **and** additive `analytics` (same service, JSON-safe, no Laravel route hrefs).

Flutter: `/dashboard` with from/to, customer, product, project, lifecycle, payment-method filters, apply, reset, hero KPIs, attention, top customers, overdue/recent lists.

Class: **A**. Status: **COMPLETE**.

### 2. Billing dashboard

Web: `BillingDashboardController`. API: `GET /billing`. Flutter: `/billing`. Class: **A**. **COMPLETE**.

### 3. Sales hub

Web: `SalesController`. API: `GET /sales` with from/to. Flutter: `/sales` with period filters. Class: **A**. **COMPLETE**.

### 4. Customers

Web module page is a list of outstanding/invoices. Full customer identity lives on the customer model used by Finance forms.

Flutter: list/search/pagination, detail (related invoices/quotes/payments/receipts/contracts), create/edit with party type, VAT, CR, phone, email, WhatsApp, Saudi address block, payment terms, notes.

Class: **A**. **COMPLETE**.

### 5. Customer statements

Web: `CustomerStatementController`. API: `GET /statements`. Flutter: `/statements` with searchable customer picker, PDF/CSV. Class: **A**. **COMPLETE**.

### 6. Quotes

Web create/edit/show/index + issue/cancel/send/accept/reject/convert/PDF/delete draft.

Flutter: matching list filters (status + outcome), compose (customer, dates, currency, tax profile/mode/rate, product/free-text lines, notes, terms), detail actions including draft delete, PDF.

Quotes have **no** contract/project columns in Laravel. Not invented in Flutter.

Class: **A**. **COMPLETE**. Web quote create has multipart enctype but no file input and `QuoteService` never stores files. Flutter matches that: no quote-file UI.

### 7. Sales invoices

Web builder: type, tax subtype, ZATCA requirement flag, optional manual number, invoice_status, registered or walk-in customer, dates, currency, payment terms, project, contract, tax, lines, notes, attachments, lifecycle, checkout, payments, reverse, remind, credit notes, PDF.

Flutter: `/invoices` lifecycle chips, compose sections, walk-in vs registered customer, attachments (upload/download/delete on drafts, including Flutter web bytes), issue/send/remind/cancel/delete draft, record payment, checkout (GET/POST, no client settlement), credit/debit note from invoice, audit list, PDF.

Class: **A**. **COMPLETE**.

### 8. Credit / debit notes

Web: nested under invoice. Flutter: `/notes` list/create/detail/issue/cancel/PDF. Class: **A**. **COMPLETE**.

### 9. Payments

Web: index. Flutter: list + detail (method, date, treasury, notes, reverse, related invoice/receipt). Class: **A**. **COMPLETE**.

### 10. Receipts

Web: index/show/send/PDF. Flutter: same. Class: **A**. **COMPLETE**.

### 11. Payment reminders / checkout

Remind and checkout are invoice actions, not separate modules. Flutter implements both. Checkout uses shared billable checkout. Class: **A**. **COMPLETE**.

### 12. Contracts + billing schedules

Web: CRUD, activate/close/cancel, PDF, attachments download/destroy, schedule create/activate/pause/cancel/generate.

Flutter: list status filters, create/edit, activate/close/cancel, PDF, add schedule, generate draft invoice, activate/pause/cancel schedule, generated invoices, **file attachments** (pick on create, upload/list/download/delete on detail; closed/cancelled contracts reject new uploads).

Class: **A**. **COMPLETE**.

### 13. Expenses

Web: index/create/edit/attachment/delete. Recurring is a boolean.

Flutter: status chips, create/edit draft, categories, treasury, tax, supplier, recurring flag, attachment (path or bytes), download, delete draft. Class: **A**. **COMPLETE** for the backend that exists.

### 14. Purchases (AP invoices)

Web: `purchases.index` with search/status/supplier/from/to. Create uses the shared invoice builder with `type=purchase`.

API: `/purchases` uses `InvoiceInboxService` filters including additive `supplier_id`. Issue/cancel/PDF.

Flutter: lifecycle chips, create/edit, issue/cancel, PDF. Class: **A**. **COMPLETE**.

### 15. Purchase orders

Web: index + submit/receive/bill. Flutter: list/create/detail + those actions. Class: **A**. **COMPLETE**.

### 16. Suppliers

Web: index store/update (no dedicated show). Flutter: list/create/edit/detail (VAT, CR, address, opening balance). Class: **A**. **COMPLETE**.

### 17. Products (Finance view)

Web: `ModulePageController::products` — paginated Product catalog with sold totals. **Not** the Products CMS.

Flutter: list + detail (SKU, stock, sold totals). Create/edit of product master stays in the Products product. Class: **A** for the Finance view. Product CMS is **C**.

### 18. Inventory movements

Web: paginated `InventoryMovement`. Flutter: `/inventory`. Class: **A**. Stock receiving/POS ops are **C**.

### 19. Projects

Web: index + create. Flutter: list/create/detail (budget/revenue/costs/profit from server). Class: **A**. **COMPLETE**.

### 20. Price lists

Web: CRUD items + approve/mark-draft/cancel. Flutter: same. Class: **A**. **COMPLETE**.

### 21. Leads

Web: index/store/convert/lost. Flutter: list/create/detail/convert/lost. Class: **A**. **COMPLETE**.

### 22. Reports

Web named reports: P&L, trial balance, cash flow, balance sheet, general ledger, AR/AP aging, inventory valuation.

Flutter: `/reports` with from/to, auto-load, CSV, dataset exports. Class: **A**. **COMPLETE**.

Web reports index extra charts (period comparison widgets) are presentation extras; named reports + dashboard analytics cover the numbers. Remaining chart chrome: **B** (desktop Blade visualization, same services).

### 23. VAT hub

Web: output/input/net + rates. Flutter: `/vat`. Class: **A**. **COMPLETE**.

### 24. Accounting hub

Web: COA list, journal entries list, trial balance, monthly cash flow (read). Flutter: `/accounting` shows the same read surfaces from `GET /accounting`. Web has no journal create or COA editor. Inventing posting APIs would not match Web. Class: **A** for the read hub. **COMPLETE**. Journal/COA editors: **B** because Web does not provide them.

### 25. Fiscal years

Web: years/periods/open/close/generate monthly/set period status. Flutter: list/create/detail + those actions. Class: **A**. **COMPLETE**.

### 26. Exports

Web: dataset CSV index/download. Flutter: `/exports`. Class: **A**. **COMPLETE**.

### 27. Alerts

Web: `BusinessAlertService`. Flutter: `/alerts`. Class: **A**. **COMPLETE**. Distinct from platform Inbox.

### 28. Copilot

Web: `IntelligenceController` + `FinanceCopilotService`. Flutter: `/copilot` ask. Class: **A**. **COMPLETE**. Not a general AI assistant / Inbox merge.

### 29. Banks / treasury

Web banks: treasury accounts. Web treasury: transfers **and** bank-statement import/match/complete.

Flutter: `/banks`, `/treasury` accounts + transfer, bank-statement create/lines/suggest/accept/ignore/complete via `BankReconciliationService`. Matching does not post new ledger entries. Matched-line undo does not exist on Web or in the service.

Class: **A**. **COMPLETE**.

### 30. Settings

Web: company identity, address, tax rates, treasury accounts, invoice prefix/color/footer, allow manual numbers, logo file, ZATCA mode/secrets.

Flutter: grouped company/address/commercial fields, prefix/color/footer, allow-manual toggle, tax-rate create, treasury-account create, company logo choose/upload/preview/replace/remove, ZATCA mode read-only.

Numbering sequences / ZATCA secrets: **B**. Logo file: **A** **COMPLETE**.

### 31. Search

Web: `IntelligenceController::search`. API buckets: customers, invoices, quotes, receipts, payments, expenses, contracts, purchases, suppliers, products, projects, purchase orders, leads.

Flutter: `/search` routes those types. Class: **A**. **COMPLETE**.

### 32. Audit

Invoice detail exposes audit rows from presenter. Flutter invoice detail lists them. No secrets. Class: **A**. **COMPLETE** for what Web exposes to Finance users.

### 33. Permissions / workspace / auth

Same Spatie map via `AuthorizesFinanceApi`. Flutter hides unauthorized UI; server remains authoritative. Sanctum, Google, workspace switch, `finance_enabled` preserved. Class: **A**. **COMPLETE**.

### 34. PDFs / CSV

Invoice, quote, receipt, note, statement, contract, purchase, reports, export datasets. Flutter uses `saveAndOpenBytes` (native + web blob). Class: **A**. **COMPLETE**.

### 35. Payroll block

Class: **C**. Not implemented.

### 36. POS / Booking / Inbox / dummy Orders

Class: **C**. Not implemented.

## API additions in this pass (additive only)

- Hubs: sales, billing, vat, alerts, accounting, banks, exports
- Catalog: products, inventory, projects, price lists, purchase orders, leads
- Treasury transfers, copilot ask, fiscal years/periods
- Settings extras + tax-rate/treasury-account create
- Invoice attachments
- Dashboard `analytics`
- Purchase `supplier_id` filter
- Search extra buckets

Laravel remains the calculator. Flutter never marks invoices paid, never recomputes tax/totals/balances/ZATCA.
