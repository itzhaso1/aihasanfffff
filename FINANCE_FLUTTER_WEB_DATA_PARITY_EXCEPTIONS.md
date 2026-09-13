# Finance Flutter ↔ Web data parity — intentional differences

Only genuine product-boundary or security exclusions. Missing client data that belonged in the Finance Flutter product was fixed in this pass, not listed here.

| Web feature | Flutter behavior | Reason | Product boundary | Temporary? | Backend work required? |
|---|---|---|---|---|---|
| POS invoices / cashier orders on a customer | Not listed under Finance sales invoices | POS remains an independent product. Finance sales use `/sales-invoices`. | Finance ≠ POS | No | No |
| Phase 10 `/api/finance/v1/invoices` e-invoice contract | Flutter never uses this prefix | Preserve compliance contract. Client uses `/sales-invoices`. | Finance API versions | No | No |
| Payroll, Finance employees, advances, adjustments | No Flutter screens | Web HR/payroll module; not part of the Finance client contract. Dashboard omits payroll KPIs. | Finance Web admin vs client | No | No unless a payroll client is scoped |
| Leads / price lists / copilot / fiscal-year admin | No Flutter screens | Web-only admin tools. | Finance Web | No | No |
| Treasury transfers, bank-statement matching | No Flutter screens | Operational treasury workstation. Cash/bank **balances** are on the Flutter dashboard. | Finance Web | No | No |
| General ledger journal entry / COA editors | No Flutter screens | Flutter consumes GL **reports** (P&amp;L, BS, TB, GL, cash flow). Editing journals stays on Web. | Finance Web | No | No |
| Inventory movements / stock receiving UI | No Flutter inventory module | Inventory valuation **report** is available. Stock ops stay with products/POS. | Finance ≠ inventory ops | No | No |
| Reports index period-comparison table and sales-by-customer charts | Not cloned as extra analytics widgets | Named ledger reports match Web show pages. VAT/sales/purchases/expenses appear on the Flutter dashboard from `DashboardService`. | Presentation | No | No |
| Recurring expense **scheduler** | Flag only (`is_recurring`) | Laravel stores a boolean; there is no expense recurrence engine. Flutter does not invent one. | Existing backend | No | Yes, only if product later adds a scheduler |
| ZATCA XML, certificates, private keys, integration secrets | Not in Flutter; mode is read-only | Server-controlled. Settings update strips secrets. | Security | No | No |
| Invoice/quote numbering sequences | Prefix shown read-only | Sequences stay server-side. | Security | No | No |
| Dummy Orders for Finance checkout | Never created | Shared billable checkout. GET availability creates no Payment. POST may create pending checkout. Settlement is webhook-only. | Payments | No | No |
| Pixel-identical Blade UI | Native Flutter layout | Data parity, not visual parity. | Client UX | No | No |
| WhatsApp / Inbox messaging inside Finance | Not moved into Finance | Independent Inbox product. Customer WhatsApp **number** is a Finance customer field and is shown. | Finance ≠ Inbox | No | No |
| Authoritative totals calculated in Flutter | Not done | Server Money/tax/statement/report values only. Line payload omits `total`/`tax_amount`. | Architecture | No | No |
