# QA-AUDIT

Audit date: 2026-09-13  
PHPUnit was **not** executed in this audit (no-fix / no-run-repair rule). Historical claim: 284 passed / 2 skipped on 2026-09-02.

---

## 1. Inventory

| Suite | Location | Count (files) |
| --- | --- | --- |
| PHP Feature + Unit | `tests/` | 162 `*Test.php` |
| Money / tax / e-invoice harness | `tests/Unit/Money`, `Unit/Finance`, `Unit/EInvoicing` | many |
| Tenancy / security | `Tenancy/WorkspaceIsolationTest`, `Security/*` | present |
| Finance | `tests/Feature/Feature/Finance/*` | large |
| POS | `tests/Feature/Feature/Pos/*` | present |
| Mobile API | `tests/Feature/Mobile/*` | present |
| Subscriptions | `Subscription*Test` | present |
| Flutter chat | `apps/hasim/test` | present |
| Flutter finance | `apps/hasim_finance/test` | sidebar/visual |
| Flutter cashier | `apps/hasim_cashier/test` including `pos_local_core_test.dart` | present |
| E2E browser | none first-party (no Dusk/Playwright in composer/package) | **Missing** |
| Load tests | listed as later phase I | **Missing** |

Coverage percent: UNKNOWN — no `coverage` artifact in this pass.

---

## 2. What is tested well

- Workspace IDOR 404s on many finance writes
- OTP enumeration, role hierarchy, API keys, inventory authz
- Money integer math
- Journal immutability / period guard (PlatformCore / FinancialCore)
- Tax engine + e-invoice cryptographic **harness** (not live ZATCA)
- POS module + cashier UX + table QR
- Mobile auth/workspace
- Secure upload unit test
- Webhook signature/replay unit tests

---

## 3. Dangerous gaps

| Gap | Why it matters |
| --- | --- |
| Full suite not re-run 2026-09-13 | Website builder retirement + quotes + finance UI may have broken tests |
| No MySQL / InnoDB lock tests | Double payment / stock races |
| No E2E across Flutter ↔ Laravel | Offline sync, Google login |
| No chaos (worker down, Redis down) | Business continuity unknown |
| Pint non-blocking / no PHPStan | Quality gate weak |
| CI workflow **absent** in this tree | Nothing fails the merge |
| Flutter Windows env broken historically | Mobile QA skipped |
| Central email 2 tests skipped | Inbox send path |
| Policies exist for some models only (12) | Many finance models rely on controller checks |

---

## 4. Error handling (logical)

| Failure | User message | Retry | Log | Error ID |
| --- | --- | --- | --- | --- |
| API 401 mobile/cashier | Arabic session expired | re-login | Laravel | no correlation id |
| Finance API | `FinanceApiErrorRenderer` codes | unknown | yes | `code` string, not UUID |
| Validation | 422 errors map | n/a | n/a | n/a |
| Payment webhook fail | job fail log (no payload) | queue retry UNKNOWN | `Queue::failing` | no Sentry |
| Upload fail | RuntimeException Arabic | user retry | unknown | no |
| DB down | 500 generic | no | yes | no |

---

## 5. Finding

### AUD-QA-001
- Severity: High
- Title: Quality gates described in docs are not in this workspace
- Location: claimed `.github/workflows/ci.yml` vs missing `.github`
- Impact: Production can ship broken isolation
- Priority: P0
