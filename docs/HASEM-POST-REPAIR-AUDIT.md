# HASEM — POST-REPAIR ENGINEERING AUDIT

Date: 2026-09-02  
Branch: `cursor/codebase-hardening-beca`  
Stack: PHP 8.3.6 / Laravel 13.29.0  
Prior audit score: **58/100**

## 1. Executive Summary

Critical privilege-escalation, inventory/API-key authorization, OTP enumeration, expense reversal, billing-schedule duplicates, scheduler overlap, and appointment feature-gating were verified in code and fixed with the smallest safe changes. Architecture was not rewritten. God services were left intact on purpose.

Full suite after repair: **286 tests, 284 passed, 0 failed, 0 errors, 2 skipped**.

Honest overall score: **73/100**. This is a production-capable SaaS codebase with remaining scale and maintainability debt, not a greenfield 90+.

## 2. Baseline (Phase 0)

Recorded before this repair branch’s test fixes:

| Suite | Passed | Failed | Errors | Skipped |
| ----- | -----: | -----: | -----: | ------: |
| Full  |    252 |      8 |      1 |       2 |

Known baseline failures (not treated as regressions of this work):

| Test | Status | Error | Root cause | Component |
| ---- | ------ | ----- | ---------- | --------- |
| Appointment payment link | fail | `payment_link` null | KYC ineligible + local provider | Appointments / payments |
| Public booking / website builder | fail | 422 vs 201 | Friday closed default | Appointments |
| Email isolation | fail | 302 not 404 | Bindings before workspace context | Email / tenancy |
| Email send success | fail | missing success flash | Mock `send()` returned null | Email inbox |
| Email failed send | fail | expected SMTP text | Controller sanitizes errors | Email inbox |
| Salary advance remaining | fail | 500 vs 300 | repayment `draft` vs schema `posted` | Finance |
| Finance employees | fail | redirect, no row | missing finance feature flag | Finance |
| CentralEmailService | skip | PHP SIGEXIT | array mailer on this VM | Central email |

PHPUnit env: SQLite `:memory:`, `QUEUE_CONNECTION=sync`, `CENTRAL_EMAIL_MAILER=array`.

## 3. Fixes Implemented

See Change Log below. Highlights:

- Server-side role hierarchy in `WorkspaceAccess` + `RolePermissionController`
- Inventory / API key authorization aligned with membership + Spatie permissions
- OTP: generic responses, no code in HTTP, no workspace listing before verify, throttle
- Expense delete: reversal + treasury restore, idempotent, transactional
- Billing occurrence unique key
- Invoice refresh uses `withSum` (no per-invoice payment query)
- Scheduler `withoutOverlapping()`
- Appointments dashboard **and** mobile appointment APIs gated; public booking ungated
- Sanctum global `expiration` stays `null`; per-token TTL 30d/60d
- CI workflow; production checklist; CORS without `*`
- Merchant GMV payment links always require eligibility; local appointment sandbox uses `local_sandbox` context

## 4. Security Fixes

| Severity | Finding | Status | Evidence | Fix | Test |
| -------- | ------- | ------ | -------- | --- | ---- |
| Critical | Role/permission self-escalation | FIXED | `RolePermissionController` had no hierarchy | `WorkspaceAccess::canAssignRole` / `canSyncPermissions` | `RolePermissionAuthorizationTest` |
| High | Inventory any member | FIXED | web/API inventory | `canViewInventory` / `canManageInventory` | `InventoryAuthorizationTest` |
| High | API keys any member | FIXED | `ApiKeyController` | owner/admin | `ApiKeyAuthorizationTest` |
| High | OTP user enumeration | FIXED | JSON `data.otp`, verify form workspace list | never return OTP; no pre-verify workspace list | `OtpProtectionTest` |
| High | Invalid `membership_role` enum writes | FIXED | invite `receptionist` vs DB enum | `persistableMembershipRole()` | `EmployeeInvitationAcceptanceTest` |
| Medium | Public assistant | ACCEPTED RISK | website widget | rate 8/min 40/day, max 800 chars, throttle 20/min | `AssistantChatTest` |
| Medium | Local provider skipped merchant KYC | FIXED | `PaymentService::requiresLiveMerchantOnboarding` | always assert for `merchant_*` | `MerchantVerificationTest` |
| Medium | Web OTP brute force | FIXED | named limiters | `otp-request` / `otp-verify` | `OtpProtectionTest` |
| Low | Sanctum expiration null | ACCEPTED RISK | mobile/cashier need long sessions | per-token expires_at, not global | `SanctumTokenExpirationTest` |
| Info | Duplicate migration timestamp | DEFERRED | may be deployed | documented, not renamed | n/a |
| Info | God services | DEFERRED | Pos/Appointment size | no split this pass | existing POS/appointment tests |

## 5. Accounting Fixes

- **Expense delete:** draft → soft delete, no journal. Posted/paid → reverse JE (`expense_reversal`), restore treasury if paid non-credit, status `cancelled`. Repeat delete is a no-op.
- **Treasury:** `TreasuryBalanceService::adjust` with `lockForUpdate`. Concurrent SQLite tests are not a substitute for production row locks on MySQL/InnoDB.
- **Invoice state:** `invoice_status` + `payment_status` are source of truth. Legacy `status` is synced via `InvoiceStateService::toLegacyStatus()`. Column **not** dropped.
- **Billing schedules:** `billing_occurrence_key` + unique `(billing_schedule_id, billing_occurrence_key)`.

## 6. Database Fixes

- Added occurrence-key unique index (new migration `2026_09_02_210000_*`).
- Did **not** expand `workspace_users.membership_role` enum. Extra invite roles map onto enum values.
- Did **not** rename `2026_09_01_120000_*` duplicates.

## 7. Performance Fixes

- `refreshIssuedStatuses` already used `withSum`; regression test bounds payment SELECT count.
- Inventory create form: limit 100 products / 200 variants + search.
- Queue/cache/session remain **database** locally. Redis is a production recommendation only.

## 8. Authorization Improvements

`WorkspaceAccess` is the shared policy helper for members, API keys, inventory, payment gateways, role assign/sync. Finance/POS still use elevated membership roles (`owner/admin/manager`) plus Spatie `can()`.

## 9. Testing Improvements

Added/extended: role, inventory, API key, OTP, expense integrity, billing idempotency, invoice query bound, Sanctum TTL, appointment feature gate (web + mobile), upload reject, tenancy product 404, assistant payload limit, invitation role mapping.

Email inbox success tests now return a stub `EmailLog` from the sender mock (root cause: null dereference, not a flash-string tweak).

## 10. CI/CD

`.github/workflows/ci.yml`: checkout, PHP 8.3, composer, Pint (non-blocking), **tests fail the job**, npm ci + build, composer/npm audit (continue-on-error).

## 11. Observability

`Queue::failing` logs job name + exception message (no payloads). Role assign/sync already log actor/target. Sentry not added (optional; would be a new dependency).

## 12. Refactoring

No PosOrderService / AppointmentService split. Extraction without a proven seam would be churn.

## 13. Remaining Risks

- Public unauthenticated assistant (cost/abuse; mitigations in place).
- Database drivers for queue/cache/session under load.
- Duplicate migration timestamps if operators rename them later.
- `membership_role` enum still narrower than product language (accountant/receptionist persist as member/agent).
- Central email send path untested on this VM (2 skipped).
- Pint `--test` on the whole repo is not clean; CI Pint is non-blocking.
- No PHPStan/Larastan yet.
- SQLite tests do not prove MySQL enum/lock behavior.

## 14. Deferred Improvements

- God-service extraction
- PHPStan incremental
- Make Pint blocking after a baseline format pass
- Expand membership enum via a **new** migration if the product truly needs accountant/receptionist as first-class membership
- Redis as default only after production Redis is guaranteed
- Drop legacy `finance_invoices.status` after a compatibility window

## 15. Production Readiness

Ready for a staged production rollout **if** operators follow `docs/PRODUCTION.md`: `APP_DEBUG=false`, secure cookies, Redis under traffic, workers + scheduler, payment secrets, CORS origins only as needed.

Not “set APP_KEY and forget”: queue/cache/session and merchant KYC still need real ops.

## 16. Final Score

| Area | /100 | Notes |
| ---- | ---: | ----- |
| Architecture | 72 | Hybrid MVC+services; god services remain |
| Security | 82 | Critical authz/OTP/KYC closed; public assistant accepted |
| Database | 74 | Unique billing key; duplicate timestamps; enum mapping |
| Performance | 70 | Invoice N+1 bounded; DB drivers still default |
| Testing | 84 | 0 fail/error; 2 skipped env; new security/finance tests |
| Maintainability | 68 | Access helper helps; large services untouched |
| Scalability | 66 | Needs Redis + workers for real traffic |
| API Design | 74 | Multiple envelopes (web/core/mobile/cashier) preserved |
| DevOps | 78 | CI exists and fails on tests |
| Documentation | 80 | Production, security, this audit |

**FINAL OVERALL: 73/100**

## Change log (this repair)

| File | Change | Reason | Risk | Tests |
| ---- | ------ | ------ | ---- | ----- |
| `WorkspaceAccess.php` | Hierarchy + persistable membership | Privilege + DB enum | Low | Role/invite tests |
| `RolePermissionController.php` | Server-side assign/sync rules | Escalation | Low | RolePermissionAuthorizationTest |
| `InventoryController` (web/API) | Authz + list limits | Authz/perf | Low | InventoryAuthorizationTest |
| `ApiKeyController` | Owner/admin | Authz | Low | ApiKeyAuthorizationTest |
| `AuthenticationService` / OTP controllers | Uniform OTP, optional workspace | Enumeration | Low | OtpProtectionTest |
| `ExpenseService` | Reversal delete | Accounting | Medium | ExpenseAccountingIntegrityTest |
| `PaymentService` | Always KYC for merchant_* | Integrity | Low | MerchantVerificationTest |
| `AppointmentBillingService` | `local_sandbox` context | Local DX without GMV KYC skip | Low | AppointmentModuleTest |
| `routes/console.php` | withoutOverlapping | Duplicate jobs | Low | schedule file review |
| `routes/mobile.php` | appointments feature gate | Feature entitlement | Low | AppointmentFeatureGateTest |
| `.github/workflows/ci.yml` | CI | Quality gate | Low | workflow |
| Email inbox tests + send nullsafe | Mock returns EmailLog | False failure | Low | EmailInboxHubTest |

## Test results

| Suite   | Before | After |
| ------- | -----: | ----: |
| Passed  |    252 |   284 |
| Failed  |      8 |     0 |
| Errors  |      1 |     0 |
| Skipped |      2 |     2 |
| Total   |    263 |   286 |

Skipped: `CentralEmailServiceTest` (array mailer SIGEXIT on this VM).

Composer audit: **no advisories**. npm audit: **0 vulnerabilities**. `npm run build`: **pass**.
