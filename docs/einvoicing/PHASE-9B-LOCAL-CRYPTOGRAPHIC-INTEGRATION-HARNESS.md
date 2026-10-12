# Phase 9B — Local Cryptographic Integration Harness

**Status:** TEST ONLY  
**Date:** 10 September 2026

> This harness does not establish the production ZATCA cryptographic profile.

Production cryptographic stamping remains **BLOCKED** (Phase 9A unresolved official conflicts).

---

## 1. Purpose

Prove that HASEM’s architecture can later accommodate whichever official ZATCA profile is locked, without choosing that profile now.

The harness shows:

- Phase 7 `invoice_hash` is the stable security-chain source
- cryptographic artifacts can be produced through isolated **test** adapters
- artifacts persist and retry idempotently
- QR cryptographic fields consume artifact boundaries (serialization only)
- certificate metadata can be stored without private keys
- multiple candidate profiles can be exercised independently
- production runtime cannot accidentally use a test profile

## 2. Explicit TEST ONLY status

Every harness identifier starts with `test.`.

| Kind | Production? |
| --- | --- |
| `TestCryptographicProfile` | No |
| `TestProfileEcdsaSigner` / `TestCryptographicStampSigner` | No (`isTestOnly() === true`) |
| `UnresolvedProductionZatcaCryptographicProfile` | Named production slot; **always throws** |
| Default `CryptographicStampSigner` | `DeferredCryptographicStampSigner` (not a ZATCA identity) |

## 3. Profile architecture

```text
CryptographicProfile
  ├── TestCryptographicProfile          (test.invoice_hash|test.signed_info + test.der|test.p1363)
  └── UnresolvedProductionZatcaCryptographicProfile   ← throws; DO NOT implement
```

The application container binds the unresolved production profile and the deferred signer. Test signers are constructed only inside tests.

## 4. Signing-input candidates

| Id | Input bytes | Production? |
| --- | --- | --- |
| `test.invoice_hash` | Phase 7 32-byte SHA-256 digest | No |
| `test.signed_info` | Synthetic `TEST_ONLY_SIGNED_INFO` payload that *references* `invoice_hash` | No — not XAdES |

Neither is the ZATCA rule. They are mutually exclusive test strategies.

## 5. Signature-encoding candidates

| Id | Bytes | Production? |
| --- | --- | --- |
| `test.der` | OpenSSL ECDSA ASN.1 DER | No |
| `test.p1363` | IEEE P1363 `r \|\| s` (64 bytes for the 256-bit test keys) | No |

Round-trip is tested. No production QR default uses these adapters.

## 6. Tag 6 candidates

Phase 8 default is **unchanged**: Tag 6 = UTF-8 of the stored Base64 `invoice_hash` string.

Harness-only:

| Id | TLV value |
| --- | --- |
| `test.tag6.raw_sha256` | 32 raw digest bytes |
| `test.tag6.base64_text` | Base64 text (same representation as Phase 8) |

`invoice_hash` is not recomputed. QR only chooses representation.

## 7. Tag 7 / 8 / 9 adapter boundaries

```text
SignatureArtifact  → TestQrTag7Encoder → QrField(tag 7)
PublicKey          → TestQrTag8Encoder → QrField(tag 8)   (SPKI or uncompressed point)
QrTag9Provider     → QrField(tag 9)
QrEncoder          → TLV → Base64
```

`QrEncoder` performs no ECDSA, hashing, certificate parsing, key generation, or signing.

Tag 9: `TestOnlyExternalCaArtifactProvider` returns a fixture marked `TEST_ONLY_EXTERNAL_CA_ARTIFACT`. It is **not** a ZATCA Technical CA signature. Production provider throws.

## 8. Persistence / idempotency

Uses existing `e_invoice_security_records` and `e_invoice_cryptographic_stamps`.

- First stamp persists the test artifact and the **test** signed-input identifier
- Retry of the same document reuses the row (no second ICV, no second security record, hash unchanged)
- Records remain immutable after finalize
- No new migrations
- No private-key columns

## 9. Dependency graph

```text
Snapshot → UBL XML → Phase 7 invoice_hash → test signature artifact → QR tags 7–9
QR Tag 6 consumes invoice_hash (representation only)
QR Tag 7 consumes SignatureArtifact
```

Forbidden edges (tested):

- invoice_hash depends on QR Tag 6 or SignatureValue
- SignatureValue depends on XML that already contains SignatureValue
- QR generation allocates ICV or mutates PIH/hash

## 10. Production guard

`CryptographicProfileGuard` fails loudly when:

- a production ZATCA profile is requested
- a test profile/signer is used with `APP_ENV=production`

Message:

```text
Production ZATCA cryptographic profile is unresolved and unavailable.
```

No silent fallback.

## 11. Unresolved ZATCA decisions (Phase 9A, still open)

1. ECDSA curve (NIST P-256 vs secp256k1)
2. Signing input (invoice hash vs XMLDSig SignedInfo)
3. Signature encoding (DER vs IEEE P1363)
4. QR Tag 7 representation
5. QR Tag 8 representation
6. QR Tag 9 encoding
7. QR Tag 6 representation (32 raw vs Base64 text)
8. Certificate / SigningCertificate digest details
9. XMLDSig SignatureMethod URI / some profile details

## 12. Deliberately NOT implemented

- Production ZATCA signing
- Production XAdES emission
- CSID / CSR / OTP / FATOORA HTTP
- Production QR Tags 7–8–9 semantics
- Choosing a production curve or encoding
- Storing private keys
- Marking invoices production-signed
- Phase 7 hash / PIH / ICV changes
- Phase 8 default Tag 6 changes
