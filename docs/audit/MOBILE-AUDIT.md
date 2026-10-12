# MOBILE-AUDIT

Audit date: 2026-09-13  
Apps: `apps/hasim`, `apps/hasim_finance`, `apps/hasim_cashier`.

---

## 1. Common stack

- Flutter SDK ^3.9, Riverpod, go_router, dio
- `flutter_secure_storage` for tokens
- `google_sign_in`
- Sanctum APIs with `X-Workspace-Id`
- No published store artifacts (`.apk` / `.ipa`) in tree

---

## 2. Per app

| Topic | hasim (chat) | hasim_finance | hasim_cashier |
| --- | --- | --- | --- |
| Navigation | `app_router.dart`, shell | `finance_shell.dart` | `app_router.dart` + stations |
| Auth | login/forgot/reset/social | login/google/forgot | login/PIN/local unlock |
| Secure storage | yes | `secure_store.dart` | yes |
| API | `/api/mobile/v1` | `/api/finance/v1` | `/api/cashier/v1` |
| Refresh tokens | not first-class | not first-class | not first-class |
| Offline | Drift/Hive outbox | online client | **first-class** local POS |
| Deep links | `notification_deep_link.dart` | UNKNOWN | UNKNOWN |
| Push | `push_service.dart` + device API | UNKNOWN | UNKNOWN |
| Permissions | OS photo/file pickers | file picker | storage, printer |
| Lifecycle | standard Flutter | standard | PIN after kill (docs) |
| Tests | present under `test/` | sidebar/visual tests | `pos_local_core_test.dart` etc. |
| Windows desktop | build artifacts exist | `windows/` runner | `windows/` runner |

---

## 3. Findings

### AUD-MOB-001
- Severity: High
- Title: Tokens in secure storage for 60 days
- Location: mobile/cashier auth services + Sanctum
- Impact: Rooted device / backup extraction
- Recommendation: Biometric re-auth; wipe on logout-all
- Effort: M
- Priority: P1

### AUD-MOB-002
- Severity: High
- Title: Chat local DB holds customer messages
- Location: `apps/hasim/lib/core/local_db/inbox_database.dart`
- Impact: Device theft = inbox PII
- Recommendation: SQLCipher / encrypt Drift; remote wipe
- Effort: L
- Priority: P1

### AUD-MOB-003
- Severity: Medium
- Title: Flutter test run on this Windows host previously failed due to `%PROGRAMFILES(X86)%`
- Location: environment, not assertions
- Impact: Mobile regressions not gated
- Recommendation: CI on Linux/macOS images
- Effort: S
- Priority: P1

### AUD-MOB-004
- Severity: Medium
- Title: Deep links / push not verified end-to-end
- Location: `notification_deep_link.dart`, `SendPushNotificationJob`
- Status: UNKNOWN — Requires runtime verification

Finance app correctly avoids local tax/GL (good). Cashier intentionally violates that for offline (see POS-AUDIT).
