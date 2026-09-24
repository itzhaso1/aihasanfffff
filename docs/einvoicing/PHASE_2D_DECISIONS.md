# Phase 2D — Validation persistence + controlled issue gate

## Pre-implementation audit (already in tree)

| Component | Location | Persisted? | Issue path? |
| --- | --- | --- | --- |
| `XmlValidator` / XSD / Schematron | `app/EInvoicing/Xml/Validation/` | Via assess | Assess + issue (2D) |
| `EInvoiceXmlValidationService` | `app/Services/EInvoicing/` | Yes | Assess; issue via gate |
| `EInvoiceValidationPersistenceService` | `app/Services/EInvoicing/` | Yes | Same |
| `e_invoice_documents` columns | migration `2026_09_24_120000_*` | Yes | `recordXmlValidation` |
| `ComplianceReadinessService` | assess + persist on issued | Yes (on assess) | Read-only assess |
| Issue gate | **Added 2D** | `ZatcaRequiredIssueGate` | Before GL (invoice) / before note GL |

Official ZATCA Schematron is **not** bundled. Only `E_INVOICE_SCHEMATRON_PATH` runs BR rules. `local-structure.sch` is harness-only (explicit path in tests).

## Transaction / rollback (Design A)

Failed required-ZATCA issue runs inside `DB::transaction`. Snapshot, issued status, validation rows on `e_invoice_documents`, and GL **roll back together**. No separate audit log for failed attempts.

## Issue gate policy (`zatca_requirement = required`)

1. Draft: seller + buyer KSA readiness (live data).
2. After immutable snapshot: XML must generate; XSD must be **`valid`**; Schematron **`invalid`** blocks; Schematron **`not_available`** does **not** block (no fake pass).
3. `not_required`: gate skipped; legacy behavior preserved.
4. Idempotent re-issue: gate **not** re-run (only `prepareFromSnapshot` reconnect).

## Compliance API

Additive `compliance.ready` on assessment payload. Issue rejection: HTTP **422**, `code: cannot_issue`, optional top-level `compliance` block.
