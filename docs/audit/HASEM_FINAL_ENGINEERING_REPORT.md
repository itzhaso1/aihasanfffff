# HASEM FINAL ENGINEERING REPORT

**Date:** 2026-09-17  
**Method:** Re-verify every prior finding against **current** code, then apply minimal safe fixes with tests. No mass refactor. Accounting rules were not rewritten.  
**Companion matrix:** [`docs/audit/AUDIT_REMEDIATION_MATRIX.md`](AUDIT_REMEDIATION_MATRIX.md)

**Production verdict:** **READY WITH DOCUMENTED RISKS**

Critical unique/race items C-01…C-06 have current-code evidence plus tests. Unresolved High items, MySQL-suite pollution, and the unauthenticated public assistant remain. This is not `READY` (open High/ops risks) and not `NOT READY` (the original Critical blockers are no longer open in SQLite-verified code).

---

## 1. EXECUTIVE SUMMARY

HASEM is still one Laravel 13 / PHP 8.3 monolith, one database, `workspaces` tenancy. Flutter clients are `apps/hasim`, `apps/hasim_finance`, `apps/hasim_cashier`.

This pass did **not** treat the old 75-finding report as truth. Each ID was re-checked. Phase 0 claims for C-01/C-02/C-03/H-04/H-11/NF-01 hold. H-01/H-02/H-03 were over-claimed as FIXED; they are **partial**. Remaining Critical items C-04…C-06 are now FIXED in code + tests.

Safe hardening shipped in this overhaul (previous session + this continuation): WhatsApp inbound idempotency, AI reply dedupe, webhook processed-after-persist, payment link reuse, inventory skip for untracked SKUs, POS inventory errors rethrown for stock/not-found, renewal period guard, mailbox SSRF at connect time (already present), mobile email `canUseEmail`, channel connect UI hidden from members, webhook header redaction, bootstrap caps, Finance ask `Idempotency-Key` (already present), Flutter finance conversation_id + workspace-switch clear.

**Not done (on purpose):** JSON-in-text Finance planner, raw SQL to Gemini, enabling `FINANCE_ASSISTANT_AGENT` by default, merging Cashier AI with Finance AI, deleting `/copilot/ask`, global `queue.after_commit=true`, float→Money migration for orders, FCM send implementation, cashier 5s poll, cashier-only RBAC (NF-12).

---

## 2. PROJECT INVENTORY

Measured on this machine, 2026-09-17.

| Item | Value |
|---|---|
| PHP | 8.3.33 |
| Laravel | 13.31.0 (`laravel/framework ^13.17`) |
| Flutter SDK | 3.47.2 (stable), Dart 3.13.2 |
| Flutter apps | SDK `^3.9.0` in all three pubspecs |
| Database (example / prod target) | MySQL (`DB_CONNECTION=mysql`) |
| PHPUnit default | SQLite `:memory:` |
| Redis | Configured in `.env.example`; **not proven** on this host |
| Queue (example) | `database` locally; production comment requires `redis` |
| Cache (example) | `database` locally; production comment requires `redis` |
| Session (example) | `database` locally; production comment requires `redis` |
| Filesystem | `local` |
| Mail (example) | `resend` |
| AI | `AI_DEFAULT_PROVIDER=google_ai_studio`; Gemini + OpenAI env vars present |
| Gemini | `GEMINI_API_KEY`, model `gemini-2.5-flash`, Interactions client in `app/Services/AI/Gemini/` |
| OpenAI | `OPENAI_API_KEY` / `gpt-4o-mini` |
| OAuth | Google + Facebook in `.env.example` |
| Payments | Stripe + HyperPay flags default **false** |
| WhatsApp / Meta | Meta app id/secret, embedded signup, webhook routes |
| Instagram | Meta Business Login connect (`workspace.feature:whatsapp`) |
| Finance Agent flag | `FINANCE_ASSISTANT_AGENT=false` (must stay off until explicitly enabled) |

**File counts**

| Kind | Count |
|---|---:|
| PHP `app/` | 887 |
| PHP `tests/` | 204 |
| Controllers | 194 |
| Services | 263 |
| Models | 129 |
| Jobs | 15 |
| Events | 10 |
| Listeners | 2 |
| Policies | 17 |
| Middleware | 6 |
| Form Requests | 42 |
| Commands | 9 |
| Migrations | 118 |
| Route files | 9 |
| Dart (all `apps/`) | 368 |
| Dart `hasim/lib` | 107 |
| Dart `hasim_finance/lib` | 50 |
| Dart `hasim_cashier/lib` | 116 |
| Composer `require` packages | 10 (plus `require-dev`) |

`Route::` matches (registrations, not unique URLs): web 366, finance-api 196, mobile 87, cashier 66, api 63, platform 25, auth 17.

---

## 3. ORIGINAL AUDIT STATUS

75 unique IDs. Full rows: [`AUDIT_REMEDIATION_MATRIX.md`](AUDIT_REMEDIATION_MATRIX.md).

| Bucket | Count | FIXED | PARTIALLY_FIXED | OPEN | FALSE_POSITIVE | NEEDS_RUNTIME_VERIFICATION |
|---|---:|---:|---:|---:|---:|---:|
| Critical | 6 | 6 | 0 | 0 | 0 | 0 |
| High | 20 | 4 | 10 | 5 | 1 | 0 |
| Medium | 35 | 3 | 9 | 22 | 0 | 1 |
| Low | 14 | 2 | 1 | 11 | 0 | 0 |
| **Total** | **75** | **15** | **20** | **38** | **1** | **1** |

Phase 0 over-claimed H-01/H-02/H-03 as fully FIXED; current status is PARTIALLY_FIXED.

H-16 (`AiSetting::logs()` on `workspace_id`) is **FALSE_POSITIVE**: `ai_settings.workspace_id` is unique and `AiLog` is workspace-scoped.

---

## 4. SECURITY REMEDIATION

Shipped or confirmed:

- Tenant fail-closed Eloquent scope (`WorkspaceScopedModel`) still throws without context.
- Mailbox SSRF denylist (private IPv4/IPv6, link-local, metadata) at **config save and SMTP/IMAP connect**.
- Email: `canUseEmail` on mobile inbox/send.
- Channels: OAuth/connect requires manager-level `canManageWhatsAppChannels`; connect buttons hidden from members.
- Attachments: Sanctum + signed URL + workspace match (H-04).
- Upload: content MIME + extension allowlist + blocked `php`/`exe`/… client extensions (H-18 partial).
- Webhook headers: `Authorization` / signatures redacted (`WebhookHeaderSanitizer`).
- WhatsApp inbound unique + retry-safe persist.
- Subscription running_guard + renewal `active_period_guard`.
- Finance assistant: no `workspace_id` authority to Gemini; tools execute in `WorkspaceContext`; conversation owner+workspace checks (`FinanceAgentConversationTest`).

Still open: public `POST /assistant/chat` (throttle only), FCM stub, some public-disk uploads, User `$fillable` includes `password`.

---

## 5. TENANT ISOLATION

Invariant remains: `workspace_id` + global scope + membership middleware. Cross-workspace reads must 404.

Proven in SQLite critical suite: `FailClosedWorkspaceTenancyTest`, `WorkspaceIsolationTest`, `JobIsolationExecutionTest`, `FinanceAgentConversationTest` (workspace A cannot use B’s `conversation_id` / Gemini interaction id; user A cannot read user B’s conversation).

WhatsApp webhook: if `phone_number_id` maps to 0 or >1 workspaces, the event is **dropped** (logged). That is fail-closed, not “pick a tenant”.

`DevicePushToken` is **not** globally scoped (NF-07 partial). Service methods require `workspace_id`. Documented on the model.

---

## 6. AUTHORIZATION / RBAC

| Area | Gate |
|---|---|
| Email use | owner/admin/manager or `email.manage` |
| Email accounts | same + `canManageEmailAccounts` |
| WhatsApp / Instagram connect | owner/admin/manager or `whatsapp.manage` |
| AI settings | owner/admin/manager or `ai.manage` |
| Finance assistant | finance client + AI feature + assistant permission (`authorizeAssistantMember`) |
| Cashier API | POS feature + membership (**no** dedicated cashier role — NF-12 OPEN) |
| Attachments | auth + workspace + signature |

---

## 7. DATABASE INTEGRITY

Additive migrations used this program:

| Migration | Purpose |
|---|---|
| `2026_09_17_100000_scope_whatsapp_meta_ids_per_workspace` | Composite unique Meta IDs |
| `2026_09_17_100100_add_subscription_running_guard` | One running subscription / workspace |
| `2026_09_17_100200_create_workspace_order_sequences` | Locked order numbers |
| `2026_09_17_220000_add_subscription_billing_attempt_period_guard` | One active renewal attempt / period |

Email attachment `workspace_id` still nullable after backfill (H-17). Campaign `email_message_id` still lacks FK (M-23).

---

## 8. CONCURRENCY

| Path | Mechanism | Test |
|---|---|---|
| Subscriptions | `lockForUpdate` + `running_guard` unique | Phase 0 |
| Order numbers | Sequence row `lockForUpdate` | 25 sequential creates (not 100 OS threads) |
| WhatsApp inbound | Unique `(workspace_id, external_message_id)` + catch | Retry test |
| Payments pending link | Order row lock + reuse | Reuse test |
| Renewal | Subscription lock + `active_period_guard` unique | Double `processRenewals()` |
| Finance conversation | `processing_started_at` + `lockForUpdate` | SQLite skipped; **MySQL worker test failed** (see §24) |
| Finance idempotency | Unique key claim before Gemini | `FinanceAgentConversationTest` |

---

## 9. IDEMPOTENCY

- Mobile: `IdempotencyService` (user+workspace+route+key), conversation message JSON key.
- Finance `POST /api/finance/v1/assistant/ask`: `Idempotency-Key` via same service.
- Cashier sync: operation UUID (existing tests in critical suite).
- WhatsApp webhook: `firstOrCreate` on `external_event_id`.
- Payments: pending-link reuse (not a client key).

NF-09 remains partial: not every finance POST uses the middleware.

---

## 10. FINANCIAL CORRECTNESS

Finance domain still uses `App\Support\Money\Money` (integer minor units) for ledger/tax paths covered by `FinancialCoreTest` / `TaxEngineTest` **on SQLite**.

Orders/POS still use `decimal(12,2)` and float casts in places (`PaymentService` amount `(float)`). **NF-05 / NF-08 / L-03 OPEN.** No accounting formula was changed.

Expense-module expenses are still not the same as posted P&L operating expenses. Product profit is not company net profit. Finance tools keep those labels (existing agent prompt/tools). Unchanged.

---

## 11. INVENTORY

`InventoryService` skips products with `inventory_tracking = false`. Insufficient stock throws `RuntimeException`. POS rethrows that plus `ModelNotFoundException` / `InvalidArgumentException`. Other throwables still logged (NF-03 partial).

Order-number load test was updated to use untracked products so numbering is not coupled to stock.

---

## 12. PAYMENTS

Pending checkout links are reused under lock. Concurrent first-calls can still hit the provider twice (residual). Webhook replay protection tests remain in the critical suite. Stripe/HyperPay stay disabled unless env flags are on.

---

## 13. SUBSCRIPTIONS

Activation: C-02 unique + lock. Renewal: H-06 lock + `active_period_guard` (cleared on fail/expire so retry is possible). Webhook processor writes `workspace_id` on `firstOrCreate`. Duplicate webhook tests exist in `SubscriptionLifecyclePhase2Test`.

---

## 14. WHATSAPP / META

Composite unique per workspace. Inbound persist is retry-safe. Processed flag after persist+AI dispatch. Signature verification remains fail-closed when secrets are missing (existing tests). Ambiguous `phone_number_id` is not delivered.

Instagram connect: feature `whatsapp` + manager role. Member OAuth is 403. Owner OAuth test now enables the feature flag (it was redirecting to subscriptions without a plan).

---

## 15. EMAIL / CHANNELS

Mobile email requires `canUseEmail`. Mailbox hosts validated at connect. Channels index still lists status for all members; connect/manage for WhatsApp/Instagram/email accounts is gated.

---

## 16. WEBHOOKS

| Provider | Auth | Dedup | Tenant |
|---|---|---|---|
| WhatsApp | signature / fail-closed secret | `external_event_id` | phone_number_id → exactly one workspace |
| Payments | signature | replay tests | workspace on payment row |
| Subscriptions | HMAC | event id | checkout/attempt workspace, not client `workspace_id` |
| Meta | signature verifier | router | connection row |
| Resend | Svix secret required in prod comments | existing tests | central email |

Headers stored redacted.

---

## 17. JOBS / QUEUES

| Job | Notes |
|---|---|
| `ProcessIncomingWhatsAppMessage` | Unique per workspace+event; processed after persist |
| `ProcessAIResponse` | Unique per conversation+inbound; reply metadata |
| `SendPushNotificationJob` | tries/backoff; **does not send FCM** |
| `SendCampaignRecipientJob` | logs; does not rethrow |
| `SendEmailMessageJob` | unused (H-10) |

`config/queue.php` `after_commit => false` (NF-10). Production Redis should set `after_commit` true on the **queue connection**, not in the SQLite test config.

Jobs that carry `workspace_id` should call `WorkspaceContext::runForId` (WhatsApp/AI/push do).

---

## 18. PERFORMANCE

Bounded this pass: finance bootstrap lists, cashier catalog categories (200).

Not changed: cashier 5s poll, hourly invoice refresh (chunked but platform-wide), inbox correlated unread subquery, chat catalog OR-LIKE.

No production-scale 100k-order benchmark was run. Do not treat this as a load-test report.

---

## 19. AI / GEMINI AGENT

Preserved:

- `GeminiInteractionsHttpClient`
- `FinanceAgentOrchestrator` + tool registry/executor/result/semantics/period/composer
- Native function calling, multi-round tool loop
- RBAC + tenant scope + metering + audit
- Conversation tables `finance_ai_conversations` / `messages` / `tool_calls`

Gemini does **not** receive workspace_id authority, DB credentials, raw SQL, or permission authority.

Default `FINANCE_ASSISTANT_AGENT=false` — production stays on the non-agent orchestrator unless ops turn the flag on after verifying Gemini Interactions.

Grounding / injection / isolation tests: `FinanceAgentConversationTest` (SQLite, in critical suite).

---

## 20. AI CONVERSATIONS

Ownership: `workspace_id` + `user_id`. History is not financial truth (re-query tools). Expired Google interaction is not source of truth. Idempotency replays without a second Gemini/usage charge (tested).

MySQL two-process overlap test did **not** pass on this host (see §24). Treat overlapping asks as **needs re-run on a clean MySQL** before calling conversation locks production-proven.

Flutter `CopilotScreen` stores `conversation_id`, sends it on follow-ups, clears on workspace switch. `askAssistant` sends `Idempotency-Key` (`idempotent: true`). No profit/tax math in Dart.

---

## 21. FLUTTER

| App | This pass |
|---|---|
| `hasim_finance` | Conversation id + idempotent ask already wired; assistant widget tests **8 passed / 0 failed** |
| `hasim` | Not re-tested end-to-end |
| `hasim_cashier` | AI not added. 5s poll unchanged. Offline UUID sync unchanged |

Full Flutter suite / CI: **not run** (M-13 OPEN).

---

## 22. PRODUCTION CONFIGURATION

`.env.example` is local-safe (`APP_DEBUG=true`) with production comments (`APP_DEBUG=false`, Redis queue/cache/session). `runtime:check --production-strict` exists (`RuntimeCheckCommand`) and is designed to **fail** a local database/sync setup. That is correct. Do not flip example env to production values.

`HYPERPAY_MERCHANT_SANDBOX_AUTO_APPROVE=false`.

---

## 23. OBSERVABILITY

Request/workspace IDs appear in many job logs. Secrets in webhook headers are redacted. Do not log `Authorization` / API keys. Residual: some providers may still distinguish misconfiguration vs bad signature (M-19 partial). Debug Flutter URL print in `kDebugMode` (NF-11).

---

## 24. TEST RESULTS

**Invented counts are forbidden. These are actual runs on this machine.**

### PHPUnit critical (`phpunit.critical.xml`, SQLite `:memory:`)

```
Passed:     397
Failed:     0
Errors:     0
Skipped:    2
Tests:      399
Assertions: 2420
```

Skipped: `FinanceAgentMysqlConcurrencyTest` (requires mysql/pgsql, not sqlite memory).

Hardening file: `ProductionHardeningRemediationTest` **9/9** (included above).

Related: `InstagramChannelConnectTest` + `ChannelsPageTest` + hardening **20/20** in a combined run before the full critical re-run.

### PHPUnit critical MySQL (`phpunit.critical.mysql.xml`, `hasem_p0_tenancy_test`)

```
Passed:     373
Failed:     21
Errors:     2
Skipped:    (not in summary; treat as 0 unless listed)
Tests:      396
```

Failures cluster as leftover `5000.00` invoices / row counts of 2 vs 0 after `FinanceAgentMysqlConcurrencyTest`, plus:

- `test_concurrent_requests_on_the_same_conversation_are_serialized` — not `{one 200 + 409}` (possible race **or** both requests finishing sequentially on a fast box).
- `FoundationSeederPlatformAdminPasswordTest` factory independence (1 vs 0).

**This suite is not green. Do not treat MySQL isolation as proven on this database.** Recommend `migrate:fresh` on `hasem_p0_tenancy_test` and a dedicated re-run. Not done here (destructive on a shared test schema without operator confirmation that the DB is disposable).

### Full `php artisan test` (SQLite)

```
Passed:     1020
Failed:     21
Errors:     138
Skipped:    13
Tests:      1192
Assertions: 7197
```

Dominant error: `Workspace context is required to query [App\Models\Order]` in cashier/POS/finance HTTP tests **outside** the critical suite. Critical cashier sync tests still passed. One regression (`InventoryServiceTest` missing context) was fixed after this run (**1/1** on re-run); the 1192-run numbers above **do not** include that fix.

### Flutter (`apps/hasim_finance`)

```
File: test/assistant_screen_test.dart
Passed: 8
Failed: 0
Errors: 0
Skipped: 0
```

`hasim` and `hasim_cashier` full suites: **not executed**.

### Security / isolation (inside critical SQLite)

Tenancy, webhook fail-closed, AI privacy, finance agent conversation isolation, Phase 0 blockers: included in the 397 passed.

---

## 25. REMAINING FINDINGS

Highest leftover:

- H-09 FCM not actually sent  
- H-10 unused email send job  
- H-13 5s cashier poll  
- H-14 50-op sync HTTP  
- H-15 mobile API coverage  
- H-01 DNS rebinding TOCTOU  
- H-05 provider double-hit under parallel first payment  
- NF-05/08 float vs Money  
- NF-10 after_commit false  
- NF-12 cashier role  
- M-04 public `/assistant/chat`  
- MySQL critical suite red  
- Full PHPUnit suite red (workspace context in POS tests)

---

## 26. BLOCKERS

Nothing in C-01…C-06 remains open in current SQLite-verified code.

**Operational blockers before calling production “safe to enable Finance Agent”:**

1. `FINANCE_ASSISTANT_AGENT` must stay `false` until Gemini Interactions is verified in the target environment.  
2. Redis (queue uniqueness, cache, session) not verified here.  
3. MySQL conversation concurrency test did not pass.  
4. Public website assistant can spend LLM quota (`throttle:20,1` only).

---

## 27. NEEDS MANUAL VERIFICATION

- Redis `phpredis` ping, TLS, prefix isolation  
- `runtime:check --production-strict` on a real production-like VM  
- MySQL `hasem_p0_tenancy_test` after `migrate:fresh`  
- 100 concurrent order creates on MariaDB  
- HyperPay / Stripe live webhooks  
- Meta/WhatsApp signature in production secrets  
- DNS rebinding against SMTP connect  
- FCM delivery  
- 100k-order query plans  

---

## 28. RISK ACCEPTANCE

| Item | Severity | Impact | Reason left open | Recommended action |
|---|---|---|---|---|
| Public `/assistant/chat` | High/Medium | LLM cost | Product website widget | Auth, stricter throttle, or disable in prod |
| FCM stub | High | No mobile push | Integration not specified | Implement FCM or hide UI |
| Order float math | Medium | Precision drift | Accounting change needs a spec | Dedicated Money migration |
| `after_commit=false` | Medium | Jobs vs TX | Breaks SQLite tests if global true | Redis queue `after_commit=true` in prod only |
| Cashier any-member POS | Medium | Privilege | No product role decision | Explicit cashier role if required |
| MySQL test DB pollution | High (test) | False confidence | Shared schema + RefreshDatabase | Fresh schema per CI job |
| Conversation overlap | High | Double turn | MySQL test failed | Re-run workers on clean MySQL; keep 409 lock |
| 5s cashier poll | Medium | API load | Tests encode 5s | Raise interval with product OK |

---

## 29. PRODUCTION READINESS

**READY WITH DOCUMENTED RISKS**

Not `READY`: public AI cost, FCM stub, MySQL critical red, Redis unverified, Finance Agent flag off (correct), order float math.

Not `NOT READY`: original Critical C-01…C-06 are closed in current code with SQLite tests; tenant fail-closed and finance-agent isolation tests in the critical suite are green.

Enable production only with: Redis + `runtime:check --production-strict` passing, webhook secrets set, `APP_DEBUG=false`, Finance Agent flag explicit, MySQL concurrency re-verified, public assistant locked down if the site is internet-facing.
