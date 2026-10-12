# Phase 3G — Production signer foundation

**REAL PRODUCTION SIGNING IS NOT ENABLED.**

## 1. Current signer architecture

| Component | Role |
| --- | --- |
| `CryptographicStampSigner` | Boundary interface (`sign(SigningInput)`, `isProductionIdentity`, `isTestOnly`) |
| `DeferredCryptographicStampSigner` | Default app binding — always deferred / throws |
| `TestCryptographicStampSigner` | Phase 9 local PEM test signer (explicit injection only) |
| `EInvoiceCryptographicStampService` | Phase 7 hash → stamp persistence; rejects deferred default |
| `SigningInput` | Phase 7 invoice hash binary digest (`digestBytes()`), plus document scope |

## 2. ActiveIdentityResolver integration

`ProductionSigningOrchestrator` resolves:

- `environment = production`
- `purpose = production_signing`

via `ActiveIdentityResolver`, then validates credential, certificate (non–test-fixture, production env), and private key reference scope.

## 3. PrivateKeyStore boundary

Production path rejects `DevMemory` drivers and production app environment (`ProductionKeyStoreUnavailableException`). Signing must go through `PrivateKeyReference` + store — orchestrator does not load PEM from DB.

`ActiveIdentityPrivateKeyStoreTestSigner` (test-only) signs via `PrivateKeyStore::sign()` for sandbox `compliance_api` identities.

## 4. Certificate/key matching

`CertificatePublicKeyMatcher` compares certificate SHA-256 fingerprint to `PrivateKeyReference.public_key_fingerprint`. Mismatch → fail-closed before any signature.

## 5. Environment safety

Production orchestrator requires production identity + production certificate. Sandbox identities, test fixtures, and compliance-only purposes do not satisfy production signing.

## 6. Production fail-closed policy

No fake, empty, or placeholder production signatures. Final step throws `ProductionSignerUnavailableException` even when validation passes. `FailClosedProductionCryptographicStampSigner` exposes the same boundary via `CryptographicStampSigner`.

## 7. Test signer policy

- `TestCryptographicStampSigner` — existing Phase 9 PEM signer
- `ActiveIdentityPrivateKeyStoreTestSigner` — identity + key store path (`isTestOnly = true`)
- `CryptographicProfileGuard` blocks test signers in production app env

## 8. Signing input status

**Supported (Phase 7 / 9):** `SigningInput::digestBytes()` — SHA-256 binary of persisted invoice hash (`signed_input_identifier = phase7_invoice_hash_sha256_binary`).

**BLOCKED — PRODUCTION SIGNING INPUT REQUIRES APPROVED CRYPTOGRAPHIC DESIGN** for XAdES `SignedInfo`, raw XML bytes, or other ZATCA representations.

## 9. Algorithm status

**BLOCKED — OFFICIAL ZATCA / CRYPTOGRAPHIC SPECIFICATION REQUIRED** for production curve, key size, and algorithm OID policy. Test profile remains `SigningAlgorithm::TEST_PROFILE` only.

## 10. Legacy field exclusion

`finance_settings.zatca_certificate_serial` and `zatca_integration_mode` are not used by production signing or identity resolution.

## 11. Integration point

`EInvoiceCryptographicStampProductionIntegration` → `ProductionSigningOrchestrator`. **Not called from** `EInvoiceCryptographicStampService::stamp()`. Invoice issue gates unchanged.

## 12. Phase 3H dependencies

- KMS/HSM `PrivateKeyStore` implementation
- Approved production algorithm / ZATCA profile
- Real production identity activation workflow
- Optional wiring from issue/stamp flow when approved

## 13. Remaining blocked items

ZATCA certificate profile, XAdES structure, CSID semantics, production certificate acceptance, signature validation policy — **BLOCKED — OFFICIAL ZATCA / CRYPTOGRAPHIC SPECIFICATION REQUIRED**.
