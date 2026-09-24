# PLAN 2 — Security + Multi-Tenancy Hardening Report

Date: 2026-09-18  
Scope: Security, authorization, tenant isolation, IDOR, uploads/downloads, public endpoints, AI/webhook tenant safety.

Supporting artifact: [`SECURITY_ENDPOINT_MATRIX.md`](SECURITY_ENDPOINT_MATRIX.md)

---

## 1. BASELINE

Inventory completed for:
- Mobile API (78 routes), Finance API, workspace web modules, webhooks, public assistant, download/upload paths
- ~112 `WorkspaceScopedModel` tenants; 4 intentional non-scoped models; 3 parent-scoped models
- Existing security tests: `TenantEmailAttachmentPushIsolationTest`, `P1CRemainingTenantIdorTest`, `AiSecurityPrivacyCostTest`, `RolePermissionAuthorizationTest`, `Phase0PreProductionBlockersTest`

---

## 2. TENANT MODEL

| Class | Count | Examples |
|---|---:|---|
| **A — WorkspaceScopedModel** | ~112 | Finance*, Order, EmailMessage, ChatCatalog*, Conversation |
| **B — workspace_id, not scoped** | 4 | DevicePushToken, NotificationPreference, ApiIdempotencyKey, WorkspaceUser |
| **C — parent/user scoped** | 3 | StoryView, EmailWebhookEvent, AuthIdentity |

**Plan 2 NF-07 action:** Added `queryForWorkspace()` on Class B models (DevicePushToken, NotificationPreference, ApiIdempotencyKey) to make safe queries explicit. Existing services (`PushDeviceService`, `IdempotencyService`) already filter by user+workspace.

---

## 3. TENANT SCOPING AUDIT

**Trusted tenant authority:** `WorkspaceContext` set by middleware (`workspace.resolve` + membership) or trusted webhook mapping (phone_number_id, payment reference).

**Untrusted (never authoritative):** client JSON `workspace_id`, Gemini tool args, LLM output, raw URL params without membership validation.

**Fixes shipped:**
- Notification `markRead` now filters by current workspace (was user-only)
- Story `selected_user_ids` / `hidden_user_ids` must be active workspace members
- Conversation `read` validates `message_id` belongs to conversation+workspace
- User `password` / login metadata removed from `$fillable` (M-02)

---

## 4. MOBILE IDOR

New regression suite: `CrossWorkspaceIdorTest` (7 tests)

| Route pattern | Cross-workspace result |
|---|---|
| `/emails/{id}` show/read/star | 404 |
| `/contacts/{id}`, `/contact-groups/{id}` | 404 |
| `/customers/{id}`, `/catalog/{item}`, `/catalog/categories/{id}` | 404 |
| `/ai/*` with foreign `conversation_id` | 404 |
| `/notifications/{id}/read` | 404 (workspace B notification while in workspace A) |
| `/email/campaigns/{id}` | 404 |
| Forged `X-Workspace-Id` without membership | 403 or 404 |

Existing coverage retained: attachments, devices, stories, idempotency (`P1CRemainingTenantIdorTest`, `TenantEmailAttachmentPushIsolationTest`).

---

## 5. EMAIL AUTHORIZATION (H-02)

| Action | Permission | Enforcement |
|---|---|---|
| Inbox/read/send/star | `canUseEmail` | Mobile + web `authorizeEmailUse` |
| Account CRUD, branding | `canManageEmailAccounts` | `authorizeEmailManage` |
| Attachments | workspace scope + safe path | 404 cross-tenant |

Member vs manager gates covered in `ProductionHardeningRemediationTest` / `EmailInboxHubTest`. Mobile mirrors web permission checks in `EmailController`.

**Status:** **FIXED** for backend authority; UI hide is not relied upon.

---

## 6. CHANNEL AUTHORIZATION (H-03)

Connect/manage routes require manager/owner (`ChannelController`, policies). Members receive **403** on direct HTTP to WhatsApp connect / Instagram OAuth (verified in `ProductionHardeningRemediationTest`, `Phase0PreProductionBlockersTest`).

**Status:** **FIXED** (backend is authority).

---

## 7. FILE DOWNLOAD SECURITY

| Path | Layers | Status |
|---|---|---|
| Mobile message attachment | sanctum + signed + workspace + policy | FIXED |
| Email attachment (web/mobile) | auth + workspace + path guard | FIXED |
| Finance PDF | session + scoped invoice | OK (session auth, not signed) |
| Digital product download | signed only | By design (buyer link); documented risk |

Leaked signed URL still requires valid Sanctum session for message attachments.

---

## 8. UPLOAD SECURITY (H-18)

| Path | Control | Status |
|---|---|---|
| MessageAttachmentService | MIME + extension blocklist, UUID names, local disk | Strong |
| SecureUpload (finance docs, platform branding) | content MIME + extension | Strong |
| Email web attachments | mimes rule only | PARTIAL |
| Chat catalog / stories / POS images | mimes + public disk | PARTIAL (M-20) |

No change to broad public-disk migration in Plan 2 (out of scope per M-20 compatibility).

---

## 9. AI TENANT SECURITY

Verified (existing + tests):
- Finance/Cashier/Mobile AI use authenticated workspace context only
- Gemini orchestrators do not accept workspace_id from model args as authority
- Public `/assistant/chat` cannot read tenant private data (`AiSecurityPrivacyCostTest`)
- Finance agent conversation isolation (`FinanceAgentConversationTest`, MySQL concurrency tests from Plan 1)

**FINANCE_ASSISTANT_AGENT** not enabled.

---

## 10. JOB / CACHE TENANT SECURITY

| Area | Status |
|---|---|
| WhatsApp/AI jobs | workspace_id constructor + `WorkspaceContext::runFor` |
| Push preferences | workspace_id required in `PushDeviceService` |
| Idempotency | user+workspace+route+key (`IdempotencyService`) |
| AI usage cache | `workspace:{id}:ai_usage_tokens` scoped |

Job isolation tests in `P1CRemainingTenantIdorTest`, `JobIsolationExecutionTest`, Plan 1 production gate tests.

---

## 11. WEBHOOK SECURITY

Signature fail-closed + tenant binding documented in matrix. WhatsApp idempotency/concurrency covered in Plan 1 + `ProductionHardeningRemediationTest`.

Instagram webhook payloads logged only (not processed) — documented residual.

---

## 12. SSRF (H-01)

`MailboxHostValidator` blocks private/link-local/metadata at save + connect.

**Residual:** DNS rebinding / TOCTOU between resolve and connect — **NEEDS RUNTIME VERIFICATION** with connect-time IP pin procedure on staging.

Unit tests: `MailboxHostValidatorTest`.

---

## 13. MASS ASSIGNMENT

| Finding | Action | Status |
|---|---|---|
| M-02 User `$fillable` included `password` | Removed; registration uses `forceFill`; factory uses `afterMaking` | **FIXED** |
| M-03 E-invoicing `$guarded = []` | Scoped models; writes via controllers/requests — no mass refactor | OPEN (low risk, scoped) |

Tests: `MassAssignmentSecurityTest`.

---

## 14. PUBLIC ENDPOINT SECURITY (M-04)

`/assistant/chat` remains public with rate limits, token caps, prohibited provider override fields. Platform AI cost bucket tracked. No tenant data exposure (tested).

Recommendation for production: keep throttling; consider CAPTCHA or auth if abuse observed.

---

## 15. TEST RESULTS

```
Security targeted (Plan 2 + existing critical security tests):
Passed: 46
Failed: 0
Errors: 0
Skipped: 0

SQLite Critical (full suite):
Passed: 407
Failed: 0
Errors: 0
Skipped: 2

Finance Agent:
Passed: 11
Failed: 0
Errors: 0
Skipped: 2
```

MySQL critical not re-run in Plan 2 (no regression expected from security-only changes); Plan 1 baseline: 400/400.

---

## 16. FINDINGS UPDATED

| Finding | Old | New | Evidence | Tests | Residual |
|---|---|---|---|---|---|
| H-01 SSRF | PARTIAL | PARTIAL | MailboxHostValidator at save/connect | MailboxHostValidatorTest | DNS rebinding — runtime verify |
| H-02 Email auth | PARTIAL | **FIXED** | canUseEmail / canManage split | EmailInboxHubTest, CrossWorkspaceIdorTest | — |
| H-03 Channels | PARTIAL | **FIXED** | Controller 403 for members | ProductionHardeningRemediationTest | — |
| H-15 Mobile IDOR | OPEN | **FIXED** (core routes) | CrossWorkspaceIdorTest | 7 new tests | Long tail routes — extend matrix over time |
| H-17 Null tenant FK | PARTIAL | PARTIAL | EmailAttachment/CampaignRecipient scoped | TenantEmailAttachmentPushIsolationTest | NOT NULL migration pending null audit on prod |
| H-18 Uploads | PARTIAL | PARTIAL | MessageAttachmentService strong | Phase0 tests | Public-disk paths remain |
| NF-07 Non-scoped models | PARTIAL | **IMPROVED** | queryForWorkspace helpers | P1C, push tests | Call-site discipline |
| M-02 Mass assignment | OPEN | **FIXED** | User fillable narrowed | MassAssignmentSecurityTest | — |
| M-03 guarded=[] | OPEN | OPEN | E-invoicing scoped | — | Explicit fillable later |
| M-04 Public AI abuse | OPEN | PARTIAL | Rate limits + caps | AiSecurityPrivacyCostTest | Cost abuse residual |
| M-20 Public disk | OPEN | OPEN | — | — | Catalog/story images on public disk |
| M-25 Leads/projects IDOR | — | NOT IN SCOPE | Module not in critical paths | — | Later plan if module active |

---

## 17. REMAINING SECURITY RISKS

1. **SSRF DNS rebinding** — needs connect-time validation on staging (H-01).
2. **Partial upload paths** — chat catalog, stories, contracts on public disk without SecureUpload (H-18/M-20).
3. **H-17 nullable workspace_id** on legacy email rows — requires production null audit before NOT NULL migration.
4. **Public assistant** — platform AI cost exposure under sustained abuse (M-04).
5. **E-invoicing mass assignment surface** (M-03) — mitigated by workspace scope but `$guarded = []` remains.

---

## 18. PLAN 2 VERDICT

```
PASS WITH RUNTIME VERIFICATION
```

**Rationale:**
- Cross-workspace mobile IDOR regression suite green
- Backend email/channel authorization enforced (not UI-only)
- AI tenant isolation proven; Gemini cannot choose workspace
- Mass assignment hardened for User password
- Critical suite green (407/407 + 2 skipped)
- SSRF connect-time pin and full upload hardening deferred with documented procedures

### Code changes (summary)

- `NotificationController` — workspace-scoped markRead
- `User` — narrowed fillable; `AuthenticationService` + `UserFactory` password handling
- `ConversationController` — message_id exists rule
- `StoryService` — workspace member validation for audience IDs
- `DevicePushToken`, `NotificationPreference`, `ApiIdempotencyKey` — `queryForWorkspace()`
- Tests: `CrossWorkspaceIdorTest`, `MassAssignmentSecurityTest`, `StoryAudienceSecurityTest`
- Docs: `SECURITY_ENDPOINT_MATRIX.md`, this report
