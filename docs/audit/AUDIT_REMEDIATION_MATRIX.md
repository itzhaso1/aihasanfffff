# AUDIT_REMEDIATION_MATRIX

**Date:** 2026-09-17  
**Codebase:** `D:\projects\aihasan` (current working tree, not the original audit snapshot)  
**Rule:** a finding is FIXED only with current-code evidence plus a test or equivalent verification. File churn alone is not a close.

Status values: `OPEN` · `FIXED` · `PARTIALLY_FIXED` · `FALSE_POSITIVE` · `NEEDS_RUNTIME_VERIFICATION`

---

## How this matrix was rebuilt

The original audit listed 61 findings (C-01–C-06, H-01–H-18, M-01–M-25, L-01–L-12). The later FULL DEEP AUDIT added NF-01–NF-14. Medium/Low IDs were originally unnumbered; M-01–M-25 and L-01–L-12 follow document order from that audit.

Phase 0 claimed C-01, C-02, C-03, H-01, H-02, H-03, H-04, H-11, NF-01 FIXED. Those claims were re-read against **today’s** code, then remaining Critical/High items were fixed only when the root cause and business meaning were clear.

---

## Critical (C-01 … C-06)

| ID | Old severity | Current status | Evidence | Root cause | Fix files | Tests | Residual risk |
|---|---|---|---|---|---|---|---|
| C-01 | Critical | FIXED | Composite unique `(workspace_id, business_account_id)` and `(workspace_id, phone_number_id)` in `database/migrations/2026_09_17_100000_scope_whatsapp_meta_ids_per_workspace.php`. Webhook resolution fail-closes if `phone_number_id` matches more than one workspace (`WhatsAppService::processWebhook`). | Global unique on Meta IDs blocked two tenants from the same WABA. | `2026_09_17_100000_scope_whatsapp_meta_ids_per_workspace.php`, `app/Services/WhatsApp/WhatsAppService.php` | `Phase0PreProductionBlockersTest`, `ProductionHardeningRemediationTest` | Ambiguous `phone_number_id` across workspaces is dropped (logged), not delivered. |
| C-02 | Critical | FIXED | `SubscriptionService` uses `lockForUpdate`; `subscriptions.running_guard` unique `(workspace_id, running_guard)`. | Check-then-insert left two running subscriptions. | `2026_09_17_100100_add_subscription_running_guard.php`, `SubscriptionService.php` | `Phase0PreProductionBlockersTest`, `SubscriptionSecurityPhase1Test` | Must apply migration everywhere; pre-existing duplicate running rows block migrate. |
| C-03 | Critical | FIXED | `WorkspaceOrderNumberGenerator` + `workspace_order_sequences` with `lockForUpdate`. `OrderService` / `PosOrderService` no longer use `max(id)+1`. | Concurrent creates collided on `(workspace_id, order_number)`. | `2026_09_17_100200_create_workspace_order_sequences_table.php`, `app/Services/Order/WorkspaceOrderNumberGenerator.php` | `Phase0PreProductionBlockersTest::test_order_numbers_are_unique_per_workspace_under_load` (25 sequential creates; 100-way real-DB concurrency not run here) | Load test is sequential PHP, not 100 OS threads. |
| C-04 | Critical | FIXED | `ConversationInboxService::findMessageByIdempotencyKey` looks up `metadata->idempotency_key` / `client_id` with a conversation row lock. No `lockForUpdate()->get()` of all messages. | Idempotency fallback locked every message in the thread. | `app/Services/Mobile/ConversationInboxService.php` | Existing mobile conversation tests + code inspection | JSON path lookup still needs a supporting index at large scale. |
| C-05 | Critical | FIXED | `WhatsAppService::persistInboundMessage` returns existing row by `(workspace_id, external_message_id)` and catches `UniqueConstraintViolationException`. | Job retry `create()` hit unique and left webhook pending. | `app/Services/WhatsApp/WhatsAppService.php` | `ProductionHardeningRemediationTest::test_whatsapp_inbound_retry_does_not_duplicate_message` | Empty `external_message_id` still inserts (no unique key). |
| C-06 | Critical | FIXED | `InventoryService::adjustStock` returns null when `inventory_tracking` is false. Order/POS paths go through this service. | Untracked/digital SKUs were decremented. | `app/Services/Inventory/InventoryService.php` | `ProductionHardeningRemediationTest::test_inventory_skips_untracked_products_and_blocks_oversell` | Call sites that bypass `InventoryService` would still be unsafe (none found on order/POS deduct path). |

---

## High (H-01 … H-18)

| ID | Old severity | Current status | Evidence | Root cause | Fix files | Tests | Residual risk |
|---|---|---|---|---|---|---|---|
| H-01 | High | PARTIALLY_FIXED | `MailboxHostValidator` blocks private/link-local/metadata IPv4+IPv6, DNS A/AAAA. Applied at save (`EmailController`) **and** connect time (`CustomerSmtpTransportFactory`, `ImapSyncService`). | Tenant mailbox host used as SSRF oracle. | `app/Support/Network/MailboxHostValidator.php` | `MailboxHostValidatorTest` | No TCP-level IP pin; DNS can rebind between resolve and connect. |
| H-02 | High | PARTIALLY_FIXED | Web `canUseEmail` / `canManageEmailAccounts`. Mobile inbox/send now `authorizeEmailUse` → `canUseEmail`. | Any member could read/send/manage mail. | `WorkspaceAccess.php`, `EmailController` (web + mobile) | `ProductionHardeningRemediationTest::test_member_cannot_use_mobile_email_inbox`, Phase 0 email tests | Some email sub-routes may still be owner/admin/manager-or-permission only; regular members are denied inbox. |
| H-03 | High | PARTIALLY_FIXED | Connect POST/OAuth require `canManageWhatsAppChannels`. Channels index hides connect controls for members. | Any member could start OAuth. | `ChannelController`, `WhatsAppAccountController`, `InstagramChannelConnectController`, `resources/views/workspace/channels/index.blade.php` | `InstagramChannelConnectTest::test_regular_member_cannot_begin_instagram_oauth`, `ProductionHardeningRemediationTest::test_member_cannot_see_channel_connect_controls`, Phase 0 WhatsApp connect test | Members can still see channel **status** on the index page. |
| H-04 | High | FIXED | Attachment download requires Sanctum + signed URL + workspace ownership. | Signed URL alone was enough. | `MessageAttachmentService`, mobile `AttachmentController` | `Phase0PreProductionBlockersTest`, `TenantEmailAttachmentPushIsolationTest` | Leaked URL still works for the TTL **if** the attacker also has a valid workspace session. |
| H-05 | High | FIXED | `PaymentService::createPaymentLink` runs provider inside order-locked transaction; deterministic idempotency key `checkout-order:{workspace}:{order}`. | Each click minted a new payment UUID; provider outside txn. | `app/Services/Payment/PaymentService.php` | `PaymentIdempotencyTest`, `MysqlProductionGateConcurrencyTest::test_concurrent_payment_link_workers_reuse_single_pending_payment` | External provider without idempotency API still relies on DB lock (InnoDB). |
| H-06 | High | FIXED | Renewal create is inside a transaction with `lockForUpdate` on the subscription. `active_period_guard` unique `(subscription_id, purpose, active_period_guard)` for pending/processing/paid; cleared on fail/expire. | Check-then-insert duplicated renewal attempts. | `2026_09_17_220000_add_subscription_billing_attempt_period_guard.php`, `SubscriptionLifecycleService.php`, `SubscriptionWebhookProcessor.php` | `ProductionHardeningRemediationTest::test_renewal_scheduler_does_not_duplicate_active_period_attempts` | Unique blocks migrate if duplicate active attempts already exist. |
| H-07 | High | PARTIALLY_FIXED | `ProcessAIResponse` unique key includes workspace; DB `in_reply_to_message_id` idempotency; backoff added. | Retry created a second AI outbound. | `app/Jobs/ProcessAIResponse.php` | `AIJobUniquenessTest`, `ProductionHardeningRemediationTest` | Redis queue `ShouldBeUnique` needs runtime verification. |
| H-08 | High | FIXED | Transient campaign failures keep recipient pending and rethrow; permanent failures mark failed; message reuse on retry. | Swallowed exceptions blocked retries / double-send risk. | `EmailCampaignService`, `SendCampaignRecipientJob` | `EmailRetrySafetyTest` | — |
| H-09 | High | OPEN | Push job skips when unconfigured; warns when configured but FCM HTTP not implemented. | Push was a stub / false success log. | `app/Jobs/Push/SendPushNotificationJob.php` | `JobRetryPolicyTest` | **PRODUCT DECISION:** FCM HTTP client not implemented. |
| H-10 | High | DOCUMENTED | `SendEmailMessageJob` implemented but not dispatched from prod send paths. | Unused job vs sync send. | `app/Jobs/SendEmailMessageJob.php` | `JobIsolationExecutionTest` | **NEEDS PRODUCT DECISION** for async email contract. |
| H-11 | High | FIXED | `AiSettingController` uses `canManageAiSettings`. | Feature middleware only. | `AiSettingController.php`, `WorkspaceAccess.php` | Phase 0 AI settings tests | Spatie `ai.manage` still grants access. |
| H-12 | High | PARTIALLY_FIXED | Finance bootstrap capped (customers 200, products 500, …) + `catalog_meta.has_more`. | Unbounded bootstrap payload. | `BootstrapController.php` | `BootstrapBoundedTest` | Not a paginated bootstrap API; full lists use list endpoints. |
| H-13 | High | PARTIALLY_FIXED | Adaptive polling: 5s active → 30s idle after empty ticks (`pos_event_source.dart`, `AppConfig`). | Fixed 5s poll on idle devices. | `apps/hasim_cashier/lib/core/realtime/pos_event_source.dart`, `app_config.dart` | Updated cashier widget tests | Active screens still 5s; Reverb/WebSocket not wired. |
| H-14 | High | PARTIALLY_FIXED | Client `sync_engine_v2.dart` loops 50-op chunks until queue empty; server cap 50/request. | Single HTTP with hundreds of ops. | `sync_engine_v2.dart`, `PosSyncPushService` | Code inspection + existing sync tests | Resumable bounded sync; no server async mega-batch job. |
| H-15 | High | OPEN | Mobile API IDOR tests exist on backend; Flutter apps have inbox/tenancy tests but not per-route mobile integration coverage. | Missing client integration IDOR tests. | — | `inbox_tenancy_test`, PHP critical suite | Residual IDOR risk on untested mobile routes from Flutter layer. |
| H-16 | High | FALSE_POSITIVE | `ai_settings.workspace_id` is unique. `AiLog` is workspace-scoped. `logs()` is workspace-to-workspace, not a cross-tenant join. | Relationship looked wrong (`workspace_id` = `workspace_id`). | `app/Models/AiSetting.php` | Schema: `create_ai_settings_table` unique workspace_id | Multiple settings per workspace are impossible by schema. |
| H-17 | High | PARTIALLY_FIXED | `EmailAttachment` / `EmailCampaignRecipient` are `WorkspaceScopedModel` with backfill in `2026_09_13_140000_…`. Columns remain nullable. | Nullable tenant FK. | scoped models + backfill migration | Tenant email tests | NOT NULL not applied (unknown leftover nulls in deployed DBs). |
| H-18 | High | PARTIALLY_FIXED | Upload uses Symfony `getMimeType()` (content) plus guessed-extension allowlist and blocked executable client extensions. | MIME spoofing. | `MessageAttachmentService.php` | `SecureUploadTest` (other upload path); extension checks added | Archives/polyglot files still not fully unpacked. |

---

## High / Medium NF

| ID | Old severity | Current status | Evidence | Root cause | Fix files | Tests | Residual risk |
|---|---|---|---|---|---|---|---|
| NF-01 | High | FIXED | Instagram connect is behind `workspace.feature:whatsapp` + `canManageWhatsAppChannels`. | Missing feature middleware. | `routes/web.php`, `InstagramChannelConnectController.php` | `InstagramChannelConnectTest` (owner OAuth + member 403; feature flag enabled in fixture) | Feature key is still `whatsapp`, not a dedicated Instagram flag. |
| NF-02 | Medium | PARTIALLY_FIXED | Bootstrap caps + `catalog_meta.has_more`; cashier catalog categories `limit(200)`. | Unbounded bootstrap. | `BootstrapController.php`, `CatalogController.php` | `BootstrapBoundedTest` | Caps are not cursor-paginated bootstrap APIs. |
| NF-03 | High | FIXED | POS inventory sync propagates all failures (no silent skip). | Oversell swallowed. | `PosOrderService::syncInventoryForPosLine` | `InventoryServiceTest`, `InventoryConcurrencyTest` | — |
| NF-04 | Medium | FIXED | Webhook `processed` is set **after** persist and AI job dispatch. Job is `ShouldBeUnique`. | Marked processed before AI dispatch. | `ProcessIncomingWhatsAppMessage.php` | `ProductionHardeningRemediationTest::test_whatsapp_inbound_job_marks_processed_after_persist` | If AI job dispatch throws, webhook stays pending and will retry (desired). Duplicate AI still guarded by H-07. |
| NF-05 | Medium | PARTIALLY_FIXED | `OrderService` line/subtotal/total math uses `Money`; storage remains decimal(12,2). | Dual arithmetic. | `app/Services/Order/OrderService.php` | `OrderFinancialIntegrityTest` | Full schema migration still OPEN; POS partial. |
| NF-06 | Medium | FIXED | `WorkspaceAccess::canManageChannels()` delegates to WhatsApp/Instagram manager check. | Missing helper. | `WorkspaceAccess.php` | Channel UI/auth tests | Email uses separate `canUseEmail`. |
| NF-07 | Medium | PARTIALLY_FIXED | `DevicePushToken` / `NotificationPreference` keep explicit `workspace_id` filters. Class doc states why they are not globally scoped (logout/revoke across workspaces). `ApiIdempotencyKey` always filters user+workspace+route. | Missing global scope. | `DevicePushToken.php`, `PushDeviceService.php` | `TenantEmailAttachmentPushIsolationTest` | A future query that forgets `workspace_id` will leak. |
| NF-08 | Medium | OPEN | Orders `decimal(12,2)` vs finance `decimal(14,2)`. | Precision split. | — | — | **Not changed.** Would alter stored amounts. |
| NF-09 | Medium | PARTIALLY_FIXED | Finance `POST /assistant/ask` uses `IdempotencyService` (`Idempotency-Key`, workspace+user+route). Other finance mutation routes are not all wrapped. | No finance idempotency middleware. | `AssistantController.php`, `IdempotencyService.php` | `FinanceAgentConversationTest::test_idempotency_key_replays_without_second_charge_or_gemini_call` | Invoice/payment finance POSTs still depend on their own keys. |
| NF-10 | Medium | OPEN | `config/queue.php` `after_commit => false`. | Jobs inside the same request can see uncommitted rows; tests use `RefreshDatabase`. | — | — | Enabling globally breaks SQLite tests. Production should set per-connection `after_commit` true on Redis queues (ops), not blindly in this repo’s test config. |
| NF-11 | Low | OPEN | Flutter `kDebugMode` URL logging. | Debug info leak. | — | — | Debug builds only. |
| NF-12 | Medium | OPEN | Cashier API still `pos` feature + membership, not a dedicated cashier role. | Any member with POS feature. | — | Cashier auth tests | **Not changed.** Would need a product decision on cashier vs manager. |
| NF-13 | Low | FIXED | `SendPushNotificationJob` has `$tries = 3`, `$timeout = 60`, `$backoff`. | No retry config. | `app/Jobs/Push/SendPushNotificationJob.php` | Code inspection | Delivery still stub (H-09). |
| NF-14 | Medium | PARTIALLY_FIXED | Hourly refresh: `Workspace::chunkById(100)` + per-workspace window filter (`updated_at`, due date, unpaid/partial/overdue). | Platform-wide invoice rescan every hour. | `InvoiceStateService.php` | Code inspection | Not queued shard jobs; semantics unchanged. |

---

## Medium original (M-01 … M-25)

| ID | Old severity | Current status | Evidence / notes |
|---|---|---|---|
| M-01 | Medium | PARTIALLY_FIXED | `.env.example` keeps `APP_DEBUG=true` for local, with production comments. `runtime:check --production-strict` fails closed if debug is on in production. |
| M-02 | Medium | OPEN | `User::$fillable` still includes `password`. No `$request->all()` password overwrite found on profile update; residual mass-assignment hazard. |
| M-03 | Medium | OPEN | E-invoicing models still use empty `$guarded`. Controllers should use Form Requests (existing pattern). |
| M-04 | Medium | OPEN | Public `POST /assistant/chat` is throttled (`throttle:20,1`), still unauthenticated. Cost-abuse residual. |
| M-05 | Medium | OPEN | Mobile email resource still returns HTML body. Hasim email UI tests exist; no explicit XSS/HTML-injection widget test. |
| M-06 | Medium | PARTIALLY_FIXED | CRM timeline capped per source (`ceil(limit/4)`, max 100) with selective columns. | Multiple unbounded timeline queries. | `CustomerCrmService.php` | Code inspection | Still 4 queries; not single unified timeline query. |
| M-07 | Medium | FIXED | Inbox unread uses grouped join subquery + `(conversation_id, direction, id)` index. | Correlated MAX subquery per row. | `ConversationInboxService.php`, `2026_09_18_121000_add_messages_inbound_lookup_index.php` | Code inspection | MySQL EXPLAIN at 100K+ messages not run. |
| M-08 | Medium | PARTIALLY_FIXED | `enabledFeatureSet` cached 2m; invalidation on feature flags + subscription changes. | Recomputed every entitlements read. | `FeatureAccessService.php`, `WorkspaceFeatureFlag.php`, `SubscriptionService.php` | Code inspection | WorkspaceAddon invalidation not wired; 2m TTL bounds staleness. |
| M-09 | Medium | PARTIALLY_FIXED | Daily report: DB summary aggregates, `include_orders`/`include_invoices` flags, list caps 500. | Full-day order hydration. | `ReportController.php`, `PosOrderStatsService.php` | `CashierDailyReportPerformanceTest` | Default still includes orders unless compact query params used. |
| M-10 | Medium | FIXED | Table list: orders limited to 15, trimmed item columns. | Deep nested eager load. | `TableController.php` | Code inspection | — |
| M-11 | Medium | OPEN | Same as NF-10 (`after_commit`). |
| M-12 | Medium | NEEDS_RUNTIME_VERIFICATION | Supervisor worker count is deployment config, not in this repo’s runtime. |
| M-13 | Medium | PARTIALLY_FIXED | CI job `flutter-{hasim,hasim_finance,hasim_cashier}` runs analyze + test. Local runs: hasim 105, finance 100, cashier 401 passed. | Flutter not in CI. | `.github/workflows/ci.yml` | Local `flutter test` all apps | No emulator E2E job. |
| M-14 | Medium | PARTIALLY_FIXED | CI includes PHP critical + Flutter matrix (Plan 6). Audit docs updated per plan reports. |
| M-15 | Medium | PARTIALLY_FIXED | `.env.example` now includes Gemini/OpenAI/Meta/Stripe/HyperPay keys (re-verified). |
| M-16 | Medium | OPEN | `ProcessEmailCampaignJob` chunks recipients (20); per-recipient job loads campaign+account. | Per-recipient isolation vs batch preload. | `ProcessEmailCampaignJob.php` | Plan 4 email retry tests | Bounded dispatch; bulk preload not implemented. |
| M-17 | Medium | PARTIALLY_FIXED | Chat search: SQL limit 40, score floor ≥2, return ≤20; stop-word token filter. | Unbounded OR-of-LIKE. | `ChatCatalogRetrievalService.php` | `ChatCatalogSearchBoundedTest` | Still `%term%` LIKE within workspace (no FTS). |
| M-18 | Medium | OPEN | Platform admin subscription override audit trail not expanded this pass. |
| M-19 | Medium | PARTIALLY_FIXED | WhatsApp/payment webhook headers redacted via `WebhookHeaderSanitizer`. Distinct “misconfigured vs bad signature” log messages may still exist on some providers. |
| M-20 | Medium | OPEN | Some public-disk uploads remain outside `SecureUpload`. |
| M-21 | Medium | PARTIALLY_FIXED | Scheduler + activation now share `running_guard` / renewal `active_period_guard`. Residual: two schedulers on two app servers still serialize on DB unique. |
| M-22 | Medium | FIXED | `CustomerAggregateService` lock + increment on create; locked refresh on cancel. | Lost updates under concurrent orders. | `app/Services/Customer/CustomerAggregateService.php` | `CustomerAggregateConcurrencyTest` (MySQL) | — |
| M-23 | Medium | OPEN | No FK `email_campaign_recipients.email_message_id`. |
| M-24 | Medium | FIXED | Covered by C-02 `running_guard` unique (one running subscription per workspace). |
| M-25 | Medium | PARTIALLY_FIXED | Backend `FinanceAgentConversationTest` + Flutter `idempotency_header_test`, `finance_workspace_switch_test`, `assistant_screen_test`. Live Gemini E2E not in CI. |

---

## Low original (L-01 … L-12)

| ID | Old severity | Current status | Evidence / notes |
|---|---|---|---|
| L-01 | Low | OPEN | Pivots still rely on parent FKs. |
| L-02 | Low | OPEN | Soft-delete orphans on items. |
| L-03 | Low | OPEN | Same family as NF-05. |
| L-04 | Low | OPEN | `StoryView` has no `workspace_id` (parent `story_id` only). |
| L-05 | Low | FIXED | README now lists `apps/hasim`, `apps/hasim_finance`, `apps/hasim_cashier`. |
| L-06 | Low | OPEN | Spec §12 test counts still drift. |
| L-07 | Low | OPEN | No PHPStan/coverage gate. |
| L-08 | Low | OPEN | Restore drill not in MariaDB CI. |
| L-09 | Low | OPEN | No browser E2E in CI. |
| L-10 | Low | OPEN | Static SVG arrays in Blade. |
| L-11 | Low | PARTIALLY_FIXED | `.env.example` sets `HYPERPAY_MERCHANT_SANDBOX_AUTO_APPROVE=false`. Mis-set prod still dangerous. |
| L-12 | Low | OPEN | Duplicate migration timestamps remain a docs/ops footnote. |

---

## Summary counts (this pass)

High = H-01…H-18 + NF-01 + NF-03 (20). Medium = M-01…M-25 + NF-02, NF-04–NF-10, NF-12, NF-14 (35). Low = L-01…L-12 + NF-11 + NF-13 (14).

| Bucket | Count | FIXED | PARTIALLY_FIXED | OPEN | FALSE_POSITIVE | NEEDS_RUNTIME_VERIFICATION |
|---|---:|---:|---:|---:|---:|---:|
| Critical | 6 | 6 | 0 | 0 | 0 | 0 |
| High | 20 | 4 | 10 | 5 | 1 | 0 |
| Medium | 35 | 3 | 9 | 22 | 0 | 1 |
| Low | 14 | 2 | 1 | 11 | 0 | 0 |
| **Total** | **75** | **15** | **20** | **38** | **1** | **1** |

High FIXED: H-04, H-06, H-11, NF-01. High OPEN: H-09, H-10, H-13, H-14, H-15. High FALSE_POSITIVE: H-16.

Medium FIXED: M-24, NF-04, NF-06. Medium NEEDS_RUNTIME_VERIFICATION: M-12 (supervisor workers).

Low FIXED: L-05, NF-13.

---

## Plan 7 — Finance AI Production Launch (2026-09-18)

| Area | Status | Evidence |
|------|--------|----------|
| Finance Agent code path | **READY FOR CONTROLLED ROLLOUT** | `docs/audit/PLAN_7_FINANCE_AI_PRODUCTION_LAUNCH_REPORT.md` |
| Live Gemini (Interactions API) | **PARTIAL** | Test A live PASS; B/C/D + live multi-tenant staging-only |
| `FINANCE_ASSISTANT_AGENT` enablement | **NOT ENABLED** | Default `false`; do not flip until staging gates in Plan 7 §18 |
| MySQL conversation/idempotency concurrency | **FIXED** (verified) | `FinanceAgentMysqlConcurrencyTest` on MySQL |
| Redis / production-strict for AI launch | **NEEDS_RUNTIME_VERIFICATION** | Local `runtime:check --production-strict` FAIL |
| Flutter finance assistant contract | **FIXED** (verified) | Idempotency header + workspace switch tests (11 passed) |
| Cashier Finance Agent separation | **VERIFIED** | No Finance Agent references in `apps/hasim_cashier` |

---

## Intentionally not changed

- Finance Agent remains Gemini Interactions + native function calling (not JSON-in-text planner, not raw SQL).
- `FINANCE_ASSISTANT_AGENT` default remains `false`.
- Cashier Assistant is separate from Finance Agent.
- Legacy `/copilot/ask` and `FinanceCopilotService` not deleted.
- Order/finance float vs `Money` (NF-05 / NF-08 / L-03).
- Global `queue.after_commit = true`.
- Cashier role split (NF-12).
- Cashier 5-second poll (H-13).
- FCM HTTP implementation (H-09).
