# Phase 2C — UBL 2.1 + ZATCA XML completeness (decision log)

**Scope:** Immutable snapshot → `EInvoiceDocument` → `UblMapper` → UBL XML (no CSID, no crypto, no validation persistence in this track).

**Evidence hierarchy used in this repo:**

1. Bundled OASIS UBL 2.1 XSD (`resources/einvoicing/schemas/ubl-2.1/`)
2. In-repo code comments citing ZATCA *Electronic Invoice XML Implementation Standard* v1.2 (19 May 2023) — **PDF not bundled**; external URL referenced in `docs/einvoicing/PHASE-9A-ZATCA-CRYPTOGRAPHIC-SPECIFICATION-RECONCILIATION.md`
3. Domain enums / Phase 6 tests (`InvoiceTypeCode`, `InvoiceTransactionCode`, debit root)
4. `docs/finance-tax-engine.md` for `tax_breakdown` semantics

---

## Gap matrix (audit)

| # | Area | Current implementation | Expected (ZATCA/UBL) | Evidence | Verified? | Change in 2C? |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | XML declaration | `XmlBuilder` emits UTF-8 XML 1.0 | Standard XML declaration | OASIS UBL samples / XSD | Inferred (structure) | No |
| 2 | Namespaces | Invoice/CreditNote root + `cac`/`cbc` URIs from `UblNamespaces` | UBL 2.1 ch. 10 namespaces | `UblNamespaces.php`, `resources/einvoicing/schemas/README.md` | Partial (OASIS + README cites ZATCA ch. 10) | No |
| 3 | UBLVersionID | `2.1` | UBL 2.1 | `UblNamespaces::UBL_VERSION`, Phase 6 test | Verified (project convention + XSD) | No |
| 4 | CustomizationID | **Not emitted** | KSA-specific customization string when mandated | No authoritative value in repo; XSD optional (`minOccurs="0"`) | **Unverified** | **UNVERIFIED — DO NOT CHANGE** |
| 5 | ProfileID | `reporting:1.0` | KSA reporting profile | `UblNamespaces::PROFILE_ID`; Phase 6 regression; README cites ZATCA std ch. 10 — **exact ProfileID string not quoted in repo** | **Unverified (value)** | No (preserve + test) |
| 6 | Invoice ID | `cbc:ID` = snapshot document number | Document identifier | `UblMapper`, snapshot | Verified (mapping) | No |
| 7 | UUID | RFC 4122 UUID v5 from snapshot identity | KSA-1 / `cbc:UUID` | `DocumentUuid.php` | **Unverified (ZATCA lifecycle rules)** | No |
| 8 | IssueDate | From snapshot issue date (date part) | Issue date | `UblMapper::issueDate` | Verified (mapping) | No |
| 9 | IssueTime | From `issued_at` when present | Issue time | `UblMapper::issueTime` | Verified (mapping) | No |
| 10 | InvoiceTypeCode | 388 / 381 / 383 from domain | UNCL1001 subset | `InvoiceTypeCode` enum, Phase 6/10 | Verified (domain + tests) | No |
| 11 | Transaction code (`name` attr) | 0100000 / 0200000 from snapshot | KSA transaction bitmap | `InvoiceTransactionCode`, `InvoiceTypeCatalogTest` | Verified (domain) | No |
| 12 | DocumentCurrencyCode | Snapshot currency | ISO 4217 | `UblMapper` | Verified (mapping) | No |
| 13 | TaxCurrencyCode | **Not emitted** | Sometimes required when tax currency differs | Not audited in bundled XSD as mandatory for all cases | Unverified | No |
| 14 | Supplier | `AccountingSupplierParty` from snapshot seller | KSA seller fields | Phase 6 + 2B readiness rules | Verified (mapping) | No |
| 15 | Customer | `AccountingCustomerParty` from snapshot buyer | KSA buyer / walk-in rules | Phase 6 + 2B | Verified (mapping) | No |
| 16 | PaymentMeans | Only if `payment.regulatory_code` non-empty | UNCL4461 / KSA mapping | `UblMapper::mapPaymentMeans`; **no authoritative code table in repo** | **Unverified (codes + mandatory rules)** | No mapper table invented |
| 17 | AllowanceCharge (doc) | Header discount → `ChargeIndicator=false` | Document allowance | Phase 6 tests | Verified (mapping) | No |
| 18 | TaxTotal | Header `TaxAmount` + N × `TaxSubtotal` | Sum of subtotals | Phase 2C `TaxSubtotalProjector` | Verified (snapshot math) | **Yes — multi subtotal** |
| 19 | TaxSubtotal | One row per `tax.breakdown` entry; fallback single row | Per category/rate groups | `docs/finance-tax-engine.md`, `TaxCalculationService` buckets | Verified (Finance engine output) | **Yes** |
| 20 | LegalMonetaryTotal | Subtotal, allowance, tax excl/incl, payable | UBL totals | Phase 6 tests | Verified (mapping) | No |
| 21 | Invoice lines | Lines from snapshot; line allowances | UBL line model | Phase 6 | Verified (mapping) | No |
| 22 | BillingReference | Credit/debit → original invoice ID + issue date | Reference original tax invoice | Phase 6 | Verified (mapping) | No |
| 23 | Notes | `reason` or `notes` → `cbc:Note` | Free text | `UblMapper` | Verified (mapping) | No |
| 24 | Supply/delivery date | `Delivery/ActualDeliveryDate` when `supply_date` set | Supply date | `mapDelivery` | Verified (mapping) | No |
| 25 | Tax categories | S/Z/E/O via `UblTaxCategory` | UNCL5305 subset | `UblTaxCategory.php` cites §11.2.4 — list in code | Partial (list in code, PDF not in repo) | No |
| 26 | Exemption reason/code | From line; official codes only in XML | VATEX-SA-* | `UblTaxCategory::officialExemptionCodes()` | Partial (list in code) | No |
| 27 | VATEX codes | Whitelist enforced at map time for doc-level exempt/OOS header | §11.2.4 | `UblMapper::assertExemptionRequirements` | Partial | No |
| 28 | ICV | Security enricher, not mapper body | KSA-16 | Phase 7 docs/code | Out of 2C scope | No |
| 29 | PIH | Security chain | KSA-13 | Phase 7 | Out of 2C scope | No |
| 30 | Invoice hash | Security chain | BR-KSA-26 | Phase 7 / 9A doc | Out of 2C scope | No |

---

## Regulatory-sensitive decisions

### CustomizationID

| Field | Detail |
| --- | --- |
| **Question** | What is the required `cbc:CustomizationID` for KSA e-invoices? |
| **Current implementation** | Element omitted (`UblMapper` does not set it). |
| **Evidence source** | Bundled UBL XSD marks element optional; no KSA value in `docs/einvoicing/*` or `resources/einvoicing/*`. |
| **Decision** | **UNVERIFIED — DO NOT CHANGE** until official PDF or ZATCA artifact is added to the repo. |
| **Confidence** | Unverified |
| **Code impact** | None |
| **Test** | Phase 6 structure tests (absence not asserted) |

### ProfileID `reporting:1.0`

| Field | Detail |
| --- | --- |
| **Question** | Is `reporting:1.0` the correct KSA ProfileID? |
| **Current implementation** | `UblNamespaces::PROFILE_ID` → `cbc:ProfileID`. |
| **Evidence source** | Phase 6 regression; README references ZATCA XML Implementation Standard ch. 10 — **does not quote ProfileID literal**. |
| **Decision** | Preserve existing value; treat as **project convention pending official PDF in repo**. |
| **Confidence** | Unverified (literal), Inferred (reporting phase naming) |
| **Code impact** | None |
| **Test** | `Phase6UblXmlFoundationTest::test_standard_tax_invoice_xml_structure` |

### Debit note root element

| Field | Detail |
| --- | --- |
| **Question** | Must debit notes use `DebitNote` root or `Invoice` + type 383? |
| **Current implementation** | `Invoice` root, `InvoiceTypeCode` 383, transaction code from domain. |
| **Evidence source** | `Phase6UblXmlFoundationTest`, `InvoiceTypeCode::DebitNote`, Phase 2C issued snapshot XSD test. |
| **Decision** | **UNVERIFIED against official PDF** — keep current behavior (consistent with Phase 6 acceptance). |
| **Confidence** | Unverified (ZATCA), Verified (HASEM domain) |
| **Code impact** | None |
| **Test** | `Phase2CUblIssuedSnapshotXsdTest::test_debit_note_issued_snapshot_uses_invoice_root_and_type_383` |

### UUID (KSA-1)

| Field | Detail |
| --- | --- |
| **Question** | UUID v5 deterministic from snapshot — acceptable for ZATCA lifecycle? |
| **Current implementation** | `DocumentUuid::fromDocument()` — UUID v5, stable per snapshot identity. |
| **Evidence source** | `DocumentUuid.php` |
| **Decision** | Keep; **official persistence / regeneration rules not in repo**. Phase 3+ validation dependency. |
| **Confidence** | Unverified (ZATCA) |
| **Code impact** | None |
| **Test** | `Phase6UblXmlFoundationTest::test_same_document_produces_equivalent_xml` |

### TaxSubtotal grouping

| Field | Detail |
| --- | --- |
| **Question** | How many `TaxSubtotal` elements for mixed-tax invoices? |
| **Current implementation** | One per row in snapshot `tax.breakdown` (grouped by Finance `TaxCalculationService` on `(tax_profile_type, tax_rate)`); if breakdown missing/empty, single subtotal from header tax + totals. |
| **Evidence source** | `docs/finance-tax-engine.md`; `TaxSubtotalProjector.php`; `UblMapper::mapTaxTotal` |
| **Decision** | Map breakdown verbatim; do not recalculate in mapper. Exemption code/reason on subtotal taken from first matching line (same classification + rate). |
| **Confidence** | Verified (Finance snapshot contract) |
| **Code impact** | `TaxSubtotalProjector.php`, `UblMapper.php` |
| **Test** | `Phase6UblXmlFoundationTest::test_tax_breakdown_projects_one_tax_subtotal_per_category_row`; `Phase2CUblIssuedSnapshotXsdTest::test_mixed_tax_breakdown_emits_one_tax_subtotal_per_category` |

### PaymentMeans regulatory codes

| Field | Detail |
| --- | --- |
| **Question** | Map Finance payment methods to UNCL4461 / KSA codes? |
| **Current implementation** | Omit `PaymentMeans` unless snapshot `payment.regulatory_code` is set. |
| **Evidence source** | `UblMapper::mapPaymentMeans`; no mapping table in repo. |
| **Decision** | **UNVERIFIED — DO NOT CHANGE** (no `PaymentMeansCodeMapper` without authoritative table). |
| **Confidence** | Unverified |
| **Code impact** | None |
| **Test** | Phase 6 (implicit omission) |

### Document-level charges (`ChargeIndicator=true`)

| Field | Detail |
| --- | --- |
| **Question** | Should invoice-level charges appear in UBL? |
| **Current implementation** | Not mapped. |
| **Evidence source** | Finance totals model / snapshot — no first-class invoice charge field identified for sales invoices. |
| **Decision** | Document as **unsupported**; do not invent charges. |
| **Confidence** | Verified (product scope) |
| **Code impact** | None |
| **Test** | N/A |

### PrepaidAmount / amount_paid

| Field | Detail |
| --- | --- |
| **Question** | Map `amount_paid` to `PrepaidAmount`? |
| **Current implementation** | `PayableAmount` uses `amount_due` ?? total; **PrepaidAmount not emitted**. |
| **Evidence source** | `UblMapper::mapLegalMonetaryTotal` |
| **Decision** | **UNVERIFIED — DO NOT CHANGE** until UBL/ZATCA mandatory rules confirmed from official doc in repo. |
| **Confidence** | Unverified |
| **Code impact** | None |
| **Test** | Phase 6 payable assertions |

### Official Schematron / BR-KSA

| Field | Detail |
| --- | --- |
| **Question** | Run official ZATCA Schematron in CI? |
| **Current implementation** | Local structure Schematron only; `resources/einvoicing/schemas/README.md` states ZATCA Schematron not redistributed. |
| **Decision** | No fake BR-KSA; optional env hook remains future (`E_INVOICE_SCHEMATRON_PATH`). |
| **Confidence** | Verified (repo policy) |
| **Code impact** | None in 2C UBL track |
| **Test** | Phase 2C validation track tests (separate) |

---

## Phase numbering note

A **validation persistence** track (XSD/Schematron assessment columns on `e_invoice_documents`) may exist in the codebase from an earlier “2C validation” slice. The **UBL completeness** Phase 2C spec explicitly defers validation persistence to **Phase 2D**. This document covers the UBL track only; persistence decisions belong in Phase 2D planning.
