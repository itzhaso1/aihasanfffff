# PLAN 6 — Mobile + Flutter + Client Reliability + Test Coverage Report

**Date:** 2026-09-18  
**Scope:** `apps/hasim`, `apps/hasim_finance`, `apps/hasim_cashier`, Flutter API integration, auth, workspace switching, offline/sync, client tests, CI.

---

## 1. BASELINE

### apps/hasim

| Check | Result |
| --- | --- |
| `flutter test` | **105 passed**, 0 failed |
| `flutter analyze` (after `flutter gen-l10n`) | **0 errors**, 1 warning, 7 info |
| Critical modules | auth, inbox/outbox, workspace tenancy, email UI, conversations, catalog |

**Analyze notes:** `app_localizations.dart` is generated — CI runs `flutter gen-l10n` before analyze. Without generation, analyze reports 4 errors (expected locally if l10n not generated).

### apps/hasim_finance

| Check | Result |
| --- | --- |
| `flutter test` | **100 passed**, 0 failed |
| `flutter analyze` | **0 errors**, 0 warnings, 1 info |
| Critical modules | auth, bootstrap, invoices, assistant/copilot, workspace gate |

### apps/hasim_cashier

| Check | Result |
| --- | --- |
| `flutter test` | **401 passed**, 0 failed |
| `flutter analyze` | **0 errors**, 6 warnings, 57 info |
| Critical modules | offline SQLite, sync v2, queue chunks, kitchen, tables, reports |

**NOT TESTED:** Full device E2E with live Laravel + emulator. No production FCM/push E2E.

---

## 2. AUTH

| App | Token storage | 401 handling | Logout clears state |
| --- | --- | --- | --- |
| hasim | `SecureStore` + Sanctum Bearer | `unauthorizedEvents` stream clears session | `inbox_tenancy_test` — logout wipes caches |
| hasim_finance | `SecureStore` | Dio interceptor → `_onUnauthorized` | `_clearLocalSession()` clears token + workspace pref |
| hasim_cashier | Secure storage + cloud link | API exceptions mapped | Local + cloud session separation |

**Finding:** No access tokens logged on successful requests. Error paths may log secure-store failures without token values.

---

## 3. WORKSPACE SWITCHING

| App | Clears tenant state | Test evidence |
| --- | --- | --- |
| hasim | `InboxLocalStore.clearWorkspace`, outbox isolation | `inbox_tenancy_test`, `inbox_store_tenancy_test` |
| hasim_finance | Copilot clears `_conversationId` + messages on auth workspace change | **NEW** `finance_workspace_switch_test.dart` |
| hasim_cashier | Sync/queue scoped by `workspaceId`; standalone partition 900001 | `inbox_outbox`-style queue tests, `batch_sync_v2_test`, workspace-scoped DB rows |

**Finance assistant:** `CopilotScreen` listens to `authControllerProvider` and resets conversation UI when `workspace.id` changes.

---

## 4. FINANCE AI CLIENT

### Contract (`FinanceApi.askAssistant`)

POST `assistant/ask` body:

```json
{
  "question": "...",
  "locale": "ar",
  "conversation_id": 12
}
```

**Does not send:** `workspace_id`, `user_id`, `role`, etc. (tenant from `X-Workspace-Id` header + Sanctum).

### Idempotency (fixed this plan)

**Before:** Each idempotent POST generated a **new** `Idempotency-Key` UUID inside `ApiClient.post`.

**After:**
- `ApiClient.post(..., idempotencyKey: ...)`
- `askAssistant(..., idempotencyKey: ...)`
- `CopilotScreen._ask` generates **one UUID per user send** and passes it through

**Tests:** `idempotency_header_test.dart` — same key on retry; body field audit.

### UX

- `_busy` disables send / quick prompts during request
- API errors mapped via `ApiException` (no stack traces in UI)
- Conversation ID preserved across turns; cleared on workspace switch

**NOT TESTED:** Live Gemini follow-up chain against staging API (mock/fake only in unit/widget tests).

---

## 5. CASHIER OFFLINE/SYNC

| Concern | Implementation | Tests |
| --- | --- | --- |
| Offline UUID ops | Local queue + client references | `offline_order_flow_test`, `e2e_offline_sync_simulation_test` |
| Chunked push (50) | `sync_engine_v2.dart` loops until empty | `batch_sync_v2_test`, Plan 5 verified |
| Duplicate prevention | Idempotency / client ref reuse | `cashier_table_create_kitchen_test` TEST 8 |
| Workspace isolation | All local rows keyed by `workspace_id` | DB tests across suite |
| Stock failure visibility | Errors propagate (not silent success) | `pos_hardening_test`, sync classifier tests |
| Unsynced wipe guard | `LocalDataWipeService._assertNoUnsyncedBusiness` | `local_data_wipe_test`; shell test fixture fixed with `syncStatus: synced` |

**Auto-sync interval:** 15s (Plan 5). Tests updated to use `AppConfig.autoSyncSeconds` dynamically.

---

## 6. HASIM — EMAIL / CHANNELS / ATTACHMENTS

| Area | Client behavior | Test / status |
| --- | --- | --- |
| Email UI | Compose/list/detail widget tests | `email_*_ui_test.dart` |
| Email permission denied | Backend enforced (`canUseEmail`); client shows API error | **M-05 OPEN** — HTML body still returned; client must not render as HTML |
| Channels connect | Web/mobile RBAC (not Flutter-initiated for members) | Backend tests; no Flutter connect flow in hasim app |
| Attachments | Signed URL + auth required (backend) | No dedicated Flutter attachment download test |
| Workspace stale data | Inbox session generation + clear on switch/logout | `inbox_tenancy_test` |

---

## 7. API CONTRACTS

- Finance models parse server decimals as strings/display (`FinanceAssistantFact`, invoice totals) — **display only**, no client recalculation of tax/GL.
- Cashier casts JSON numbers defensively (`json_numbers_test`, `string_num_cast_test`).
- Bootstrap/catalog caps align with Plan 5 backend meta.

**Residual drift risk:** Large finance model surface; parity tests exist (`data_parity_screens_test`, `field_coverage_test`) but not every endpoint.

---

## 8. LOCAL DATA SAFETY

| Data | Storage | Clear on logout | Clear on workspace switch |
| --- | --- | --- | --- |
| Auth token | Secure storage | Yes | N/A (switch updates header) |
| Finance workspace id | SharedPreferences | Yes | Updated on switch |
| Hasim inbox cache | SQLite + Hive outbox | Yes (logout) | Per-workspace clear |
| Cashier POS data | Drift SQLite | Cloud unlink flows | Rows scoped by `workspace_id`; no cross-tenant merge |

No API secrets embedded in Flutter binaries (OAuth client IDs are public client config via `--dart-define`).

---

## 9. RETRY / IDEMPOTENCY

| Pattern | Policy |
| --- | --- |
| GET / read | Safe to retry |
| Finance assistant POST | **Idempotency-Key required** (fixed) |
| Hasim outbox send | Same `clientId` on retry |
| Cashier sync push | Chunked; queue marks status; no blind 5xx retry on all Dio calls |

---

## 10. CI

**Added** `.github/workflows/ci.yml` job `flutter-{hasim,hasim_finance,hasim_cashier}`:

1. `flutter pub get`
2. `flutter gen-l10n` (hasim only)
3. `flutter analyze --no-fatal-infos --no-fatal-warnings`
4. `flutter test --reporter expanded`

Runs in parallel with existing PHP critical suite. **No emulator E2E job** (documented gap).

---

## 11. TEST COVERAGE (matrix summary)

| App | Module | API | Auth | Workspace | Offline | Tests | Risk |
| --- | --- | --- | --- | --- | --- | --- | --- |
| hasim | Inbox/chat | ✓ | ✓ | ✓ | outbox | 105 | Medium — attachment download untested |
| hasim | Email | partial | ✓ | ✓ | outbox | UI tests | M-05 HTML body |
| hasim_finance | Assistant | ✓ | ✓ | ✓ | N/A | +idempotency, +switch | Low for client contract |
| hasim_finance | Invoices/dashboard | ✓ | ✓ | partial | N/A | screen tests | Medium — not all CRUD paths |
| hasim_cashier | Sync | ✓ | ✓ | ✓ | ✓ | 401 tests | Low for offline core |
| hasim_cashier | POS checkout | ✓ | ✓ | ✓ | ✓ | extensive | Medium — live network flakes |

### New tests (Plan 6)

- `apps/hasim_finance/test/idempotency_header_test.dart`
- `apps/hasim_finance/test/finance_workspace_switch_test.dart`
- Fixed Plan 5 regressions in cashier tests (`auto_sync_kitchen_test`, `hasim_top_notice_test`, `shell_nav_test`)

---

## 12. TEST RESULTS

```
hasim:
  Passed: 105
  Failed: 0
  Errors: 0
  Skipped: 0

hasim_finance:
  Passed: 100
  Failed: 0
  Errors: 0
  Skipped: 0

hasim_cashier:
  Passed: 401
  Failed: 0
  Errors: 0
  Skipped: 0

Flutter Analyze (after gen-l10n where needed):
  hasim: 0 errors, 1 warning, 7 info
  hasim_finance: 0 errors, 0 warnings, 1 info
  hasim_cashier: 0 errors, 6 warnings, 57 info
```

---

## 13. FINDINGS UPDATED

| ID | Status | Notes |
| --- | --- | --- |
| **M-13** | **PARTIALLY_FIXED** | Flutter analyze + test jobs added to GitHub Actions for all 3 apps |
| **H-15** | **OPEN** | Flutter/mobile API IDOR coverage still uneven vs route count; backend tests exist |
| **M-05** | **OPEN** | Mobile email HTML body — client display safety not fully tested |
| **M-14** | **PARTIALLY_FIXED** | CI now includes Flutter; docs/historical wording may still drift |
| **M-25** | **PARTIALLY_FIXED** | Finance assistant client tests expanded; live agent E2E not run |
| **L-09** | **PARTIALLY_FIXED** | Cashier sync/offline tests extensive; emulator E2E not in CI |

### Code fixes (Plan 6)

1. Finance idempotency key stable per assistant send
2. CI Flutter matrix job
3. Cashier test fixture alignment (synced invoice for wipe test; auto-sync interval; top notice margins)

---

## 14. REMAINING RISKS

1. **No emulator/integration E2E** against live API in CI
2. **H-15** — many mobile routes lack dedicated Flutter/integration IDOR tests
3. **M-05** — email HTML rendering safety relies on client discipline
4. **Dependency upgrades** — 28–58 packages have newer incompatible versions; no major upgrade performed
5. **hasim analyze** requires `flutter gen-l10n` (CI handles this)
6. Finance assistant **follow-up conversation** not tested end-to-end against real backend in this pass

---

## 15. PLAN 6 VERDICT

## **PASS WITH DOCUMENTED TEST COVERAGE GAPS**

**Rationale:**
- All three app **`flutter test` suites pass** locally (606 total tests)
- **No production-blocking analyzer errors** (infos/warnings only)
- **CI now executes Flutter analyze + test** for all apps
- Critical client fixes: **finance idempotency**, **workspace switch clears assistant state**, cashier test reliability
- E2E emulator tests, full mobile IDOR matrix, and live Gemini integration remain **explicitly not claimed**

---

*End of Plan 6 report.*
