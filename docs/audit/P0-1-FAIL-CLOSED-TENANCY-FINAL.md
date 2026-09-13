# P0-1 FINAL — Fail-closed workspace tenancy

**Date:** 2026-09-13  
**Scope:** P0-1 only. No P0-2. No POS / Tax / UI work.

---

## FINAL STATUS = P0-1 CLOSED

All closure gates below passed on executed tests (SQLite regression + isolated MariaDB subset).

| Gate | Result |
| --- | --- |
| Model isolation | PASS |
| No-context query / create / update / delete | PASS |
| Cross-tenant read / write / delete / create | PASS |
| Platform Admin explicit bypass | PASS |
| Payment webhook isolation | PASS |
| No first / last / default workspace fallback on payment webhook | PASS |
| Required Job execution tests | PASS |
| ExpireStories bypass protected | PASS |
| Dangerous `withoutGlobalScopes` on tenant request / required job / webhook paths | 0 remaining |
| MySQL verification | PASS |
| Regression | PASS |

---

## 1. What was fixed in this remediation

### 1.1 Payment webhook tenancy

`PaymentService::processWebhook()` no longer:

- picks `PaymentGateway` by provider + `first()`
- settles an `Order` by `order_number` alone

It now:

1. Verifies the provider via `PaymentGatewayManager::resolveByName()` (no gateway row fallback).
2. Binds a tenant only through `resolveTrustedWebhookBinding()`:
   - payments with the same provider and `checkout_reference = $reference`
   - **or** `order_id` in orders that have that `order_number`
   - **exactly one** distinct `workspace_id`, otherwise **no write**
3. Writes `WebhookEvent` / `Payment` / `Order` only inside `runForId($workspaceId)` and with `where('workspace_id', $workspaceId)`.

If workspace cannot be determined uniquely: no `WebhookEvent`, no Payment mutation, no Order mutation, safe return.

### 1.2 Job isolation (execution, not static review)

Required jobs now carry an explicit `workspaceId` (optional for in-flight payloads) and reject a parent row whose `workspace_id` does not match.

| Job | Context | Forged ID from B inside job for A |
| --- | --- | --- |
| `SendWhatsAppMessage` | `runForId` on outbound workspace | no Graph send, B stays queued |
| `SendEmailMessageJob` | `runForId` | no send, B stays pending |
| `ProcessEmailCampaignJob` | `runForId` | campaign B stays queued |
| `SendCampaignRecipientJob` | `runForId` | recipient B not sent |
| `SyncEmailInboxJob` | `runForId` | account B not synced |
| `SendPushNotificationJob` | `runForId` when `workspaceId` set | no tenant writes |
| `ProcessIncomingWhatsAppMessage` | job `workspaceId` + `runForId` inside service | WebhookEvent B stays pending |

Dispatch sites updated: `WhatsAppOutboundService`, `EmailCampaignService`, `EmailController::syncAccount`.

Missing / deleted parent: safe return, no cross-tenant read/write.

### 1.3 ExpireStories

- Global expire (`expireOldStories(null)`) requires an active explicit bypass.
- `ExpireStoriesJob` uses only `WorkspaceContext::BYPASS_STORIES_EXPIRE_SCHEDULER`.
- Bypass reasons are allowlisted. Unknown reason is rejected.
- Tenant HTTP (active route, not `platform_admin`) cannot start a bypass.
- Tenant-scoped expire (`expireOldStories($workspaceA)`) does not touch workspace B.

### 1.4 WhatsApp inbound webhook (dangerous `first()`)

`WhatsAppService::processWebhook()` no longer binds the first phone row for a `phone_number_id`. Ambiguous workspace count ≠ 1 → no write. `WebhookEvent` uniqueness includes `workspace_id`.

---

## 2. Test results

### 2.1 SQLite regression (phpunit.xml — `:memory:`)

```bash
php artisan test --testdox --filter="FailClosedWorkspaceTenancy|WorkspaceIsolation|ProductIsolation|AIServiceWorkspaceIsolation|FinancialCore|PlatformCore|PlatformAdminAuth|OtpProtection|RolePermission|ApiKeyAuthorization|ApiKeyManagement|InventoryAuthorization|MobileAuthAndWorkspace|AuthenticationTest|FinanceFlutterAuth|CashierPhase1AAuth|SubscriptionSecurity|SubscriptionLifecycle|WebhookReplay|PaymentWebhook|JobIsolationExecution|ExpireStoriesBypass"
```

| | Count |
| --- | --- |
| **PASS** | **163** |
| **FAIL** | **0** |
| **SKIPPED** | **0** |
| **ERRORS** | **0** |
| Assertions | 633 |
| Duration | 29.397s |
| Engine | SQLite `:memory:` (`phpunit.xml`) |

Previous verification (before this remediation): 132 PASS. New + extended P0-1 tests are included in the 163.

### 2.2 New / extended P0-1 tests (included above)

- `tests/Feature/Feature/Tenancy/PaymentWebhookTenancyTest.php`
- `tests/Feature/Feature/Tenancy/JobIsolationExecutionTest.php`
- `tests/Feature/Feature/Tenancy/ExpireStoriesBypassTest.php`
- `tests/Feature/Feature/Tenancy/FailClosedWorkspaceTenancyTest.php` (existing, still passing)
- `tests/Unit/PaymentWebhookSecurityTest.php` (invalid Stripe signature = **no write**)

---

## 3. MySQL verification = PASS

Not claimed from SQLite. Ran on a **separate** test database. Production/app database `hasem` was **not** used.

| Item | Value |
| --- | --- |
| Engine | MariaDB **10.4.32** (`10.4.32-MariaDB`) |
| Host | `127.0.0.1:3306` |
| App / configured database (`.env`) | `hasem` — **not used** |
| Test database | `hasem_p0_tenancy_test` (created empty, `RefreshDatabase` migrates) |
| Separate test DB? | **Yes** |
| Production data? | **No** |

**Prepare (idempotent):**

```bash
php tests/bin/mysql-p0-probe.php
```

Output: `status=READY`, `test_database=hasem_p0_tenancy_test`.

**Exact command (clean, no artisan double `--configuration`):**

```bash
php vendor/bin/phpunit -c phpunit.mysql.xml --testdox --filter="FailClosedWorkspaceTenancy|PaymentWebhook|JobIsolationExecution|ExpireStoriesBypass|WorkspaceIsolation|ProductIsolation"
```

| | Count |
| --- | --- |
| **PASS** | **56** |
| **FAIL** | **0** |
| **SKIPPED** | **0** |
| **ERRORS** | **0** |
| Assertions | 140 |
| Duration | 21.361s |
| Config | `phpunit.mysql.xml` → `DB_DATABASE=hasem_p0_tenancy_test` |

Subset covers: fail-closed CRUD, cross-tenant isolation, payment webhook isolation (including Stripe replay / invalid signature), forged job IDs, ExpireStories bypass, workspace/product isolation. Payment settle path uses `DB::transaction` + `lockForUpdate`.

---

## 4. `withoutGlobalScopes` inventory (tenant request / job / webhook)

Mass deletion was **not** done. Only unconstrained / first-tenant fallbacks on these paths were fixed.

### DANGEROUS remaining on P0-1 tenant / required-job / webhook paths: **0**

### Fixed this round (were DANGEROUS or equivalent)

| File | Method | Why it existed | Constraint now | Write protected | Risk now | Status |
| --- | --- | --- | --- | --- | --- | --- |
| `app/Services/Payment/PaymentService.php` | `processWebhook` / `resolveTrustedWebhookBinding` | Bind webhook without a tenant row | Unique payment workspace from reference; else return | No event / payment / order write if ambiguous | Cross-tenant settle | **SAFE** |
| `app/Services/WhatsApp/WhatsAppService.php` | `processWebhook` | Phone lookup by Meta id | Unique `workspace_id` or return | No `WebhookEvent` if ambiguous | First-tenant phone | **SAFE** |
| `app/Jobs/SendWhatsAppMessage.php` | `outboundMessage` | Load parent by PK | `whereKey` + optional `workspace_id` | No send if mismatch | Forged outbound id | **SAFE** |
| `app/Jobs/SendEmailMessageJob.php` | `findOwnedMessage` | Load parent by PK | `whereKey` + optional `workspace_id` | No send if mismatch | Forged message id | **SAFE** |
| `app/Jobs/Email/ProcessEmailCampaignJob.php` | `handle` | Load campaign by PK | `whereKey` + optional `workspace_id` | No status change if mismatch | Forged campaign id | **SAFE** |
| `app/Jobs/Email/SendCampaignRecipientJob.php` | `handle` | Load campaign by PK | campaign `workspace_id` must match job | No send if mismatch | Forged recipient/campaign | **SAFE** |
| `app/Jobs/SyncEmailInboxJob.php` | `handle` | Load account by PK | `whereKey` + optional `workspace_id` | No IMAP sync if mismatch | Forged account id | **SAFE** |
| `app/Jobs/ProcessIncomingWhatsAppMessage.php` | `handle` | Mark webhook processed | `whereKey` + `workspace_id` | B event not updated | Forged event id | **SAFE** |
| `app/Services/Stories/StoryService.php` | `expireOldStories` | Global scheduler | Bypass **or** explicit workspace | Tenant HTTP cannot start bypass | Unscoped expire | **SAFE** |

### REVIEW (constrained, leftover, or out of P0-1 job list)

These keep `withoutGlobalScopes` **with** a workspace key, parent FK + `runForId`, or an allowlisted scheduler bypass. Not treated as remaining DANGEROUS for P0-1 closure.

| File | Method | Why | Workspace constraint | Write protected | Risk | Status |
| --- | --- | --- | --- | --- | --- | --- |
| `PaymentService` | `createPaymentLink` / `createBillablePaymentLink` | Gateway + existing payment | `where('workspace_id', …)` | Create inside `runForId` | First **gateway in that workspace**, not first workspace | REVIEW |
| `FeatureAccessService` | flags / meters / addons | Entitlements | `where('workspace_id', $workspace->id)` | Writes inside workspace | Copy-paste miss | REVIEW |
| `WorkspaceAnalyticsService` | `summary` | Dashboard | `where('workspace_id', …)` | Read only | — | REVIEW |
| `WebsiteAssistantService` | `productsForWorkspace` | Public assistant | `where('workspace_id', $workspace->id)` | Read only | — | REVIEW |
| `DigitalDownloadService` | `resolveDigitalProduct` | Download | product `workspace_id` = item workspace | Read | — | REVIEW |
| `CustomerCrmService` | `addNote` / `timeline` | CRM | create + payments filtered by customer workspace | Yes | — | REVIEW |
| `InvoiceStateService` | `refreshIssuedStatuses` | Global invoice status job | Bypass `finance-invoice-status-scheduler` **or** `runForId` | Scheduler only | HTTP cannot invent unknown bypass | REVIEW |
| `SubscriptionLifecycleService` | `runScheduled` | Billing scheduler | Bypass `subscription-lifecycle-scheduler` | Scheduler only | Same | REVIEW |
| `SubscriptionWebhookProcessor` | `findCheckout` / `findAttempt` | Bind by provider checkout id | Workspace from that checkout/attempt; unbound → no write | Yes if no row | `first()` on globally unique provider ids — not first-workspace | REVIEW (P0-2 if provider ids can collide) |
| `EnsurePlatformAdmin` | `handle` | Platform panel | Allowlisted `platform-admin-panel` + `platform_admin` guard | Wrapped request only | God-mode by design | REVIEW / intended |
| `ProcessAIResponse` | `handle` | AI reply job (not in required list) | `runForId(conversation.workspace_id)` | Writes that conversation’s workspace | Forged conversation id processes **that** tenant | REVIEW leftover |
| `CreatePaymentLink` | `handle` | Order job (not in required list) | `runForId(order.workspace_id)` | Same | Forged order id | REVIEW leftover |
| `UpdateAIUsage` | `handle` | Usage job | Loads workspace by job `workspaceId` | Scoped to job workspace | — | REVIEW leftover |
| POS / cashier services + `PosSyncChangeObserver` | many | Offline sync / POS | Typically `workspace_id` or parent FK then workspace check | Varies | **Out of P0-1.** Do not treat as closed for P0-2/POS. | OUT OF SCOPE |

---

## 5. Files touched this remediation

### Application

- `app/Services/Payment/PaymentService.php`
- `app/Services/WhatsApp/WhatsAppService.php`
- `app/Services/WhatsApp/WhatsAppOutboundService.php`
- `app/Services/Email/EmailCampaignService.php`
- `app/Services/Stories/StoryService.php`
- `app/Support/Tenancy/WorkspaceContext.php`
- `app/Http/Controllers/Workspace/EmailController.php`
- `app/Jobs/SendWhatsAppMessage.php`
- `app/Jobs/SendEmailMessageJob.php`
- `app/Jobs/SyncEmailInboxJob.php`
- `app/Jobs/Email/ProcessEmailCampaignJob.php`
- `app/Jobs/Email/SendCampaignRecipientJob.php`
- `app/Jobs/Push/SendPushNotificationJob.php`
- `app/Jobs/ProcessIncomingWhatsAppMessage.php`
- `app/Jobs/Stories/ExpireStoriesJob.php`

### Tests / verification harness

- `tests/Feature/Feature/Tenancy/PaymentWebhookTenancyTest.php`
- `tests/Feature/Feature/Tenancy/JobIsolationExecutionTest.php`
- `tests/Feature/Feature/Tenancy/ExpireStoriesBypassTest.php`
- `tests/Unit/PaymentWebhookSecurityTest.php`
- `phpunit.mysql.xml`
- `tests/bin/mysql-p0-probe.php`

No migration history changes. No Finance/POS/Tax/UI product work beyond webhook/job tenancy.

---

## 6. Bypass allowlist (must stay in sync)

| Constant / reason | Caller |
| --- | --- |
| `platform-admin-panel` | `EnsurePlatformAdmin` |
| `subscription-lifecycle-scheduler` | `SubscriptionLifecycleService::runScheduled` |
| `finance-invoice-status-scheduler` | `InvoiceStateService::refreshIssuedStatuses(null)` |
| `stories-expire-scheduler` | `ExpireStoriesJob` |

Unknown reasons throw. Tenant HTTP with an active route cannot start a bypass. `platform_admin` may.

---

## 7. What this does **not** close

- **P0-2** (POS, tax, UI, broader product hardening) — not started.
- Leftover jobs (`ProcessAIResponse`, `CreatePaymentLink`, `UpdateAIUsage`) still resolve the parent by primary key when `workspaceId` is absent. They bind that parent’s workspace; they do not fall back to “first workspace”.
- POS `withoutGlobalScopes` surface is **REVIEW / out of scope**, not a P0-1 reopen.
- `SubscriptionWebhookProcessor` still matches checkout by provider id (`first()`). Unbound events write nothing. Treat as follow-up if provider ids are not globally unique.

---

## 8. Verdict

```
FINAL STATUS = P0-1 CLOSED
```

SQLite regression 163/163. Isolated MariaDB 56/56. Payment webhook fail-closed. Required jobs executed with forged-ID coverage. ExpireStories bypass is explicit, allowlisted, and blocked on tenant HTTP. Dangerous unconstrained `withoutGlobalScopes` on P0-1 tenant/job/webhook paths = 0. MySQL verification PASS on `hasem_p0_tenancy_test`.
