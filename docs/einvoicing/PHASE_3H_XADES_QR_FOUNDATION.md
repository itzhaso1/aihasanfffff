# Phase 3H — XAdES + QR Tags 7–9 (audit & foundation)

**REAL PRODUCTION XAdES SIGNING IS NOT ENABLED**  
**REAL ZATCA API IS NOT CONNECTED**  
**QR TAGS 7–9 ARE NOT CLAIMED AS ZATCA-COMPLIANT IN PRODUCTION**  
**PRODUCTION SIGNING REMAINS FAIL-CLOSED**

---

## Pipeline trace (current)

```
IssuedDocumentSnapshot
  → EInvoiceFactory / EInvoiceDocument (immutable snapshot)
  → EInvoiceXmlGenerator → XML
  → EInvoiceXmlValidationService (XSD assess + persist validation)
  → EInvoiceSecurityService (ICV / PIH / invoice hash) — Phase 7
  → [optional] EInvoiceCryptographicStampService (test signer injection only)
  → EInvoiceQrService (Tags 1–6 from snapshot + Phase 7 hash; 7–9 optional via stamp/mapper)
  → InvoiceIssueResult (QR not persisted as mutable row; fail-open skip on QR errors in issue path)
```

**Future insertion (not implemented):**

```
snapshot → XML → [XadesSigner] → signed XML → validate → QR Tags 7–9 (approved providers) → artifact
```

`InvoiceIssueService` does **not** call production signing or XAdES.

---

## E. QR audit

| Topic | Finding |
| --- | --- |
| **Service** | `EInvoiceQrService` + `QrEncoder` (TLV → Base64) |
| **Tags 1–6** | Built from `EInvoiceDocument` snapshot fields + `InvoiceHash` from security record |
| **Live DB** | QR does not re-read Finance invoice totals; uses snapshot + persisted `invoice_hash` |
| **Order** | After security (Phase 7) in `InvoiceIssueService`; Tags 7–9 only when caller passes stamp/mapper |
| **Failure** | Issue path: QR failure → `qrSkipReason`, logged notice (**fail-open** for issue completion) |
| **Persistence** | No dedicated mutable QR table; payload derived on demand |
| **Tags 7–9 today** | Optional via `CryptographicQrFields` / `CryptographicStampQrMapper` (TEST stamp mapping); Tag 9 external/harness only |
| **Phase 3H port** | `EInvoiceQrCryptographicTagsPort` — default defers to stamp mapper; `productionCryptographicFields()` fail-closed |
| **Providers** | `QrTag7Provider`, `QrTag8Provider`, `QrTag9Provider` — production bindings unresolved (throw) |
| **Test harness** | `TestQrCryptographicAssembler`, `TestQrTag6/7/8Encoder`, `TestOnlyExternalCaArtifactProvider` |

---

## F. XAdES audit

| Item | Status |
| --- | --- |
| `XadesEnvelopedSignature` | **Blocked stub** — `materialize()` always throws |
| `XadesSigner` (3H) | Interface + `FailClosedXadesSigner` (production unavailable) |
| `XadesSigningRequest` | Opaque XML bytes + cert metadata — **signing input semantics TBD** |
| UBL XSD | XAdES schema files present under `resources/einvoicing/schemas` — **not wired to signer** |
| Production | Disabled; no UBLExtensions emission |

**Proposed insertion point:** between validated unsigned XML and final persistence — callable only via future approved workflow, not `InvoiceIssueService` today.

---

## G. Cryptography summary

| Layer | Component |
| --- | --- |
| Stamp input (today) | `SigningInput::digestBytes()` = Phase 7 invoice hash binary (`phase7_invoice_hash_sha256_binary`) |
| XAdES input | **BLOCKED — PRODUCTION SIGNING INPUT REQUIRES APPROVED CRYPTOGRAPHIC DESIGN** |
| Test signer | `TestCryptographicStampSigner`, `ActiveIdentityPrivateKeyStoreTestSigner` |
| Production signer | `ProductionSigningOrchestrator` + `FailClosedProductionCryptographicStampSigner` — fail-closed |
| Identity | `ActiveIdentityResolver` (production + `production_signing`) |
| Keys | `PrivateKeyStore` / `PrivateKeyReference` — no PEM in QR/XAdES layers |
| Capabilities | `ProductionCryptographicCapabilities::phase3H()` — all false |

---

## H. ZATCA blocked items

**BLOCKED — OFFICIAL ZATCA SPECIFICATION REQUIRED**

- QR Tag 7/8/9 production encoding semantics and validation rules
- XAdES-B-B profile details, transforms, canonicalization, SignedInfo references
- Production ECDSA curve/OID policy (O1 conflict in-repo)
- CSID / Tag 9 CA signature provenance
- Certificate chain rules for production QR/XAdES
- Whether XML digest equals Phase 7 invoice hash for signing

---

## Phase 3I+ dependencies

- Approved cryptographic design for signing input
- Real production identity + KMS/HSM store
- ZATCA Tag 7–9 providers (non-stub)
- XAdES signer implementation behind `XadesSigner`
- Optional issue-path feature flag (explicit, not implied compliance)
