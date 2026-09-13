# Finance Tax Engine

This document describes HASEM Phase 2 tax calculation. It is the single source of truth for invoice-domain VAT math.

**This Tax Engine is NOT a ZATCA integration.**

It does not generate XML, QR, CSID, CSR, OTP, cryptographic signatures, PIH, ICV, clearance, reporting, or Fatoora submissions. A future Structured Invoice / ZATCA layer must consume the persisted tax result instead of recalculating tax.

## 1. Tax classifications

Internal classifications use `App\Enums\Finance\TaxProfileType`. Do not create a second classification vocabulary.

| Value | VAT | Taxable amount | Notes |
| --- | --- | --- | --- |
| `standard` | `taxable × rate` | Yes | Requires a rate `> 0` |
| `zero_rated` | `0` | Remains identifiable | Not the same as exempt |
| `exempt` | `0` | Remains identifiable | Not `standard` at 0% |
| `out_of_scope` | `0` | Remains identifiable | Not collapsed into exempt or zero-rated |

Never infer classification from `tax_rate == 0`. Classification is explicit on each line (`finance_invoice_items.tax_profile_type`). The invoice header `tax_profile_type` is only the default for unspecified lines.

## 2. Rate resolution

`App\Services\Finance\Tax\TaxRateResolver` is workspace-explicit. Priority for a **standard** line:

1. Explicit line `tax_rate` when the caller provided the key
2. Document/header default rate when the caller provided one
3. Active default `finance_tax_rates` row (`is_default = true`, `is_active = true`)
4. `finance_settings.default_vat_rate`
5. `TaxCalculationService::FALLBACK_STANDARD_RATE` (`15.00`) as a **technical safety fallback only**

The fallback is **not** a legal/tax-rate database.

Non-standard lines always persist and calculate at `0.00`, even if the UI still sent `15`.

`finance_tax_rates` is workspace-scoped, active/inactive, and classification-aware via `type`. It is **not** date-effective. No historical rate table was added in this phase.

The invoice builder currently always submits `tax_rate` per line, so an explicit line rate usually wins for standard lines.

## 3. Calculation formulas

All money arithmetic uses integer cents via `App\Support\Money\Money`. Percentages are applied with `Money::percentOf()` / `Money::extractInclusiveTax()`, not float `× 0.15` in services.

### Exclusive (default, current production invoices)

```
gross = round(quantity × unit_price)
discount = min(requested_discount, gross)
taxable = gross − discount
tax = classification == standard ? round(taxable × rate / 100) : 0
total = taxable + tax
```

Quantity uses 3 decimal places; unit price uses 2.

### Inclusive (opt-in, `tax_price_mode = inclusive`)

Existing invoices stay exclusive unless this field is set. Inclusive formula:

```
gross = round(quantity × unit_price)   # price already includes VAT
discount = min(requested_discount, gross)
net = gross − discount
tax = classification == standard ? round(net × rate / (100 + rate)) : 0
taxable = net − tax
total = net
```

Example: `115.00` inclusive at `15%` → tax `15.00`, taxable `100.00`, total `115.00`.

## 4. Discount handling

Invoice lines support a **fixed monetary discount only**. Percentage discounts are not supported.

Gross minus discount is the taxable base (exclusive) or the tax-inclusive net (inclusive). A discount larger than the line gross is **capped** at the gross so the taxable base cannot go negative. Negative discounts are rejected on documents.

## 5. Rounding

- Round **per line** to integer cents (half-up).
- Invoice totals are the **exact sum of already-rounded line amounts** (`Money::add`).
- Therefore `invoice.tax_amount = sum(line.tax_amount)` with **zero undocumented residual**.
- `total = taxable_amount + tax_amount` on every valid document.

Do not recalculate issued invoices when rounding policy is discussed later. Historical totals stay as stored.

## 6. Inclusive / exclusive pricing

`tax_price_mode` on `finance_invoices` and `finance_credit_notes`:

- `exclusive` (default, backfilled for historical rows)
- `inclusive` (engine-supported; omitted requests stay exclusive)

Do not silently convert historical exclusive invoices.

## 7. Mixed-tax invoices

Line classification is authoritative. The header rate must not overwrite line types.

Example:

- Line A `standard` @ 15%
- Line B `zero_rated` @ 0%
- Line C `exempt`

Each line is calculated independently, then aggregated.

## 8. Tax category totals

`TaxCalculationResult.categoryTotals` groups by `(tax_profile_type, tax_rate)`:

- taxable amount
- tax amount
- line count

Persisted on `tax_breakdown` (JSON) at create/update/issue. Future Structured Invoice / ZATCA generation must read this snapshot, not today’s `finance_tax_rates`.

## 9. Exemption handling

Nullable extension points:

- `finance_invoice_items.exemption_reason`
- `finance_invoice_items.exemption_code` (max 32, `[A-Za-z0-9._-]`)
- same columns on credit-note items

These are **not** a ZATCA exemption-code catalogue. Official codes belong to a future compliance/data-dictionary phase. Historical rows are left `null`; this phase never invents codes.

Standard lines cannot carry exemption fields. Exempt remains distinguishable from zero-rated.

## 10. Historical invoice behavior

Issued invoice tax values are authoritative. Changing `finance_settings.default_vat_rate` or `finance_tax_rates` tomorrow must not change yesterday’s invoice.

Issue() recalculates from **persisted line inputs** (quantity, price, discount, classification, stored rate, price mode) and refuses to issue if stored totals do not match. It does not re-resolve the workspace default rate.

## 11. Credit / debit behavior

`CreditNoteService` uses the same `TaxCalculationService`. Notes persist line classification, exemption fields, `tax_price_mode`, and `tax_breakdown`. They must not rewrite original invoice lines; they only update `amount_credited` / `amount_debited` and payment status.

No ZATCA BillingReference XML.

## 12. Billing schedule behavior

`BillingScheduleService` stores a line snapshot (`tax_type` / `tax_rate`) using the workspace default profile. It does **not** own VAT formulas and does not contain `15` as a local fallback. Invoice creation/finalization goes through `InvoiceService` → `TaxCalculationService`.

## 13. Purchase invoice behavior

Purchase invoices still run through the tax engine for amounts. `zatca_requirement` remains `not_required`. Sales ZATCA rules are not applied to purchases.

## 14. Future Structured Invoice boundary

Intended later (not implemented here):

```
FinanceInvoice
  → TaxCalculationService result (already persisted)
  → StructuredInvoiceFactory
  → ZATCA XML / QR / Signing
  → Fatoora
```

Phase 2 stops before Structured Invoice / ZATCA.

## Architecture

```
InvoiceService / CreditNoteService
    ↓
TaxCalculationService::calculateDocument()
    ↓
TaxCalculationResult (lines + category totals)
    ↓
FinanceInvoice / FinanceInvoiceItem (persisted)
```

`TaxService` is a float-returning facade for expenses and purchase orders (`calculateAmount` / `calculateLine`). Those methods stay permissive. Document validation lives on `calculateDocument()`.

Controllers supply input. Blade/JavaScript previews are UX only. The server result is authoritative.

Legacy invoices that previously sent `tax_rate = 0` without a type classify as `out_of_scope` so they keep 0 VAT without representing “standard at 0%”.
