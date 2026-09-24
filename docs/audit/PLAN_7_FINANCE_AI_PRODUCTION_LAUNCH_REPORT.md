# PLAN 7 — Finance AI Production Launch Report

**Date:** 2026-09-18  
**Codebase:** `D:\projects\aihasan`  
**Scope:** Production readiness assessment for Finance Gemini Agent (`FINANCE_ASSISTANT_AGENT`). **No flag enablement performed.**

---

## 1. PRECONDITION CHECK

| Plan | Verdict | Notes for Finance AI launch |
|------|---------|------------------------------|
| Plan 1 — Production Gate | **PASS WITH RUNTIME VERIFICATION REQUIRED** | MySQL concurrency proven for Finance Agent; Redis / `runtime:check --production-strict` **not verified on this machine** |
| Plan 2 — Security & Multi-Tenancy | **PASS WITH RUNTIME VERIFICATION** | AI tenant isolation tested; `FINANCE_ASSISTANT_AGENT` remains off |
| Plan 3 — Financial/Data Integrity | **PASS WITH DOCUMENTED ACCOUNTING/RUNTIME RISKS** | Tool facts use Laravel services; expense-module vs ledger semantics documented in prompts |
| Plan 4 — Jobs/Webhooks/Integrations | **PASS WITH RUNTIME VERIFICATION** | Redis unique jobs not verified locally (H-07) |
| Plan 5 — Performance/Scalability | **PASS WITH DOCUMENTED SCALE RISKS** | AI multi-tool latency under large datasets not measured |
| Plan 6 — Flutter/Test Coverage | **PASS WITH DOCUMENTED TEST COVERAGE GAPS** | Finance assistant widget tests green; no emulator E2E vs live backend |

**Gate 0 result:** No prior plan is **BLOCKED**. Operational prerequisites (Redis, production-strict runtime) remain **open for target deployment environment**.

---

## 2. FINANCE AI PRE-LAUNCH AUDIT

| Item | Status | Evidence |
|------|--------|----------|
| `FINANCE_ASSISTANT_AGENT` | **configured (off)** | `.env.example` default `false`; not enabled in audit environment |
| `GEMINI_API_KEY` | **configured (local)** | Present in local `.env` (length verified; value not logged) |
| `GEMINI_MODEL` | **configured** | Default `gemini-2.5-flash` in `config/ai.php` |
| `GEMINI_BASE_URL` | **configured** | `https://generativelanguage.googleapis.com/v1beta` |
| `AI_DEFAULT_PROVIDER` | **configured** | `google_ai_studio` |
| AI feature flag (workspace) | **verified** | `FeatureAccessService` + `ensureAi()` on assistant routes |
| AI usage limits | **verified** | `ai_usage` + `ai_tokens` consumed via `FeatureAccessService` |
| AI token limits | **verified** | `AI_MAX_TOKENS_CAP` (1024), `AiSafety::capMaxTokens()` |
| Rate limiting | **verified** | `throttle:mobile-ai` → 10/min per user on `/assistant/ask` |
| Conversation tables | **verified** | `finance_ai_conversations`, `finance_ai_messages`, `finance_ai_tool_calls` |
| Idempotency | **verified** | `IdempotencyService` on ask; MySQL concurrent same-key test |
| Audit | **verified** | `AuditLog` action `finance.assistant.ask` with tokens, rounds, interaction_id |
| Gemini client binding | **verified** | `GeminiInteractionsHttpClient` bound in `AppServiceProvider` |
| Model allowlist | **verified** | `AiSafety::resolveModel()` — Flutter/API cannot pass model/provider |

---

## 3. GEMINI LIVE VERIFICATION

| Scenario | Live Gemini | FakeGemini (SQLite) |
|----------|-------------|---------------------|
| **A — Single tool** (“كم مبيعاتنا اليوم؟”) | **PASS** | PASS |
| **B — Multi-tool** (sales + expenses + profit) | **NOT RUN LIVE** | PASS (`FinanceAgentOrchestratorTest::test_parallel_function_calls_in_one_round`) |
| **C — Multi-round** (profit drop / why) | **NOT RUN LIVE** | PASS (`FinanceAgentHardeningTest::test_company_profit_drop_question_reaches_the_agent`) |
| **D — Conversation follow-ups** | **NOT RUN LIVE** | PASS (`FinanceAgentConversationTest` — continue, history-not-truth, expired interaction) |

**Live test executed:**

```bash
FINANCE_AGENT_LIVE_GEMINI=true php artisan test --filter=FinanceAgentLiveGeminiTest
```

Result: **1 passed, 8 assertions** — real Interactions API round-trip with function calling, audit `interaction_id`, `round_count ≥ 1`, model ≠ `FakeGeminiInteractionsClient`.

**Important:** Live verification covers **Test A only**. Scenarios B–D are proven with `FakeGeminiInteractionsClient` but require staging re-run against real Gemini before Stage 3+ rollout.

---

## 4. TOOL LOOP

Verified via `FinanceAgentOrchestratorTest` + live Test A:

- Native Gemini `function_call` → Laravel tool → `function_result` → final answer
- Sequential multi-tool (`test_sequential_multi_tool_calls`)
- Parallel first-round calls (`test_parallel_function_calls_in_one_round`)
- Max rounds / max calls caps (`test_max_rounds_stops_safely`, `test_max_default_rounds_and_call_caps_stop_the_loop`)
- Unknown tool / invalid args rejected
- Duplicate tool calls not re-executed
- Failure after tools: no double charge (`FinanceAgentConversationTest::test_gemini_failure_after_tools_does_not_double_charge_or_duplicate_tools`)

**Caps (config):** `FINANCE_ASSISTANT_MAX_TOOL_ROUNDS=5`, `FINANCE_ASSISTANT_MAX_TOOL_CALLS=10`, `FINANCE_ASSISTANT_MAX_TOOLS=5`.

---

## 5. CONVERSATION

| Flow | Status | Test |
|------|--------|------|
| New + continue | PASS | `test_ask_creates_and_continues_a_conversation` |
| Archive / delete owner-scoped | PASS | `test_busy_conversation_returns_conflict_and_archive_delete_are_owner_scoped` |
| 409 busy / lock | PASS | same |
| Expired interaction retry | PASS | `test_expired_interaction_retries_without_google_id` |
| Gemini failure consistency | PASS | `test_gemini_failure_keeps_conversation_consistent_without_cross_tenant_fallback` |
| History not financial truth | PASS | `test_follow_ups_and_history_are_not_financial_truth` |
| Workspace switch (Flutter) | PASS | `finance_workspace_switch_test.dart` clears copilot state |

Bounded context: `conversation_max_messages=8`, `conversation_message_chars=500`, processing lock `90s`.

---

## 6. TENANT ISOLATION

| Attack vector | Status | Test |
|---------------|--------|------|
| A asks for B sales | PASS | `test_agent_cannot_read_another_workspace_even_with_adversarial_arguments` |
| Malicious `workspace_id` in tool args | PASS | `test_workspace_id_in_gemini_arguments_is_ignored` |
| A uses B `conversation_id` | PASS | `test_workspace_a_cannot_use_workspace_b_conversation_or_interaction` |
| Prompt injection in history | PASS | `test_prompt_injection_in_history_cannot_switch_workspace` |
| Fallback cross-tenant facts | PASS | `test_fallback_does_not_reuse_facts_from_another_workspace_request` |
| Data leak `999999` search | PASS | Assertions `assertStringNotContainsString('9000'/'999999')` across adversarial tests |

**Live multi-tenant Gemini (A=5000, B=9000):** covered by FakeGemini suite; **not re-run live** in this pass.

**MySQL concurrency:** `FinanceAgentMysqlConcurrencyTest` — 2 tests **PASS** on MySQL (serialized conversation, idempotency once).

---

## 7. RBAC

| Tool / area | Status | Test |
|-------------|--------|------|
| `payroll.*` without `payroll.view` | PASS | `test_payroll_permission_denied_is_not_in_answer` |
| Finance summary payroll gating | PASS | tool metadata + `FinanceSummaryTool` |
| Assistant route membership | PASS | `AssistantController::authorizeAssistantMember` |
| Flutter permission gates | PASS | `assistant_screen_test.dart` (reports / finance / payroll / denied) |

All 16 agent tools registered in `FinanceAssistantToolRegistry` remain workspace-scoped via `WorkspaceContext` + policies.

---

## 8. GROUNDING

| Case | Status | Test |
|------|--------|------|
| Invented numbers replaced | PASS | `test_invented_numbers_are_replaced` (Laravel fact 5000 vs Gemini 999999) |
| Product profit unavailable | PASS | `test_product_profit_remains_unavailable` |
| Unsupported period (2050) | PASS | `test_unsupported_future_period_remains_unavailable` |
| Expense-module ≠ ledger operating expenses | **documented** | `FinanceAgentSystemPrompt`, `ExpensesPeriodTool` `metric_kind`, `ProfitNetTool` labels |

Golden-style fixture (FakeGemini): sales 10000, expenses 2500, net profit 7500 — facts asserted in `test_sequential_multi_tool_calls` / `test_parallel_function_calls_in_one_round`.

---

## 9. IDEMPOTENCY

| Case | Status | Test |
|------|--------|------|
| Same key replay (no second charge/Gemini) | PASS | `test_idempotency_key_replays_without_second_charge_or_gemini_call` |
| Same key, different payload → 409 | PASS | `IdempotencyService` + controller conflict path |
| Concurrent same key → once | PASS | MySQL `test_concurrent_same_idempotency_key_executes_once` |
| Flutter sends `Idempotency-Key` | PASS | `idempotency_header_test.dart` + `CopilotScreen` UUID per send |

---

## 10. METERING / COST

| Control | Status |
|---------|--------|
| `ai_usage` per turn | PASS — single claim via `AiUsageAccountant` + `commitUsage` |
| `ai_tokens` from Gemini usage | PASS — audit meta `input_tokens`, `output_tokens`, `total_tokens` |
| Max tool rounds/calls | PASS — config caps enforced |
| Rate limit 10/min/user | PASS — `mobile-ai` limiter |
| Sensitive prompts in logs | **default off** — `AI_LOG_STORE_CONTENT=false` |

**Cost abuse:** caps stop unbounded loops; rapid 429 behavior not load-tested in this pass.

---

## 11. PERFORMANCE

| Measurement | Status |
|-------------|--------|
| End-to-end live latency | **NOT MEASURED** (single live test, no timing capture) |
| Multi-tool parallel vs sequential | **architecture supports parallel** — `test_parallel_function_calls_in_one_round` |
| Large-dataset AI latency | **NOT VERIFIED** (Plan 5 documented gap) |

Audit logs record `duration_ms` per request for production monitoring.

---

## 12. PRIVACY / RETENTION

### HASEM stores
- Conversation ID, messages, safe facts, tool audit rows
- Gemini `interaction_id` on conversation/audit when agent path runs
- Prompt content **not** stored in audit by default (`question_length` only)

### Google / Gemini
- Interactions API requests use **`store: true`** by default (`GeminiInteractionRequest`)
- Follow-up rounds use `previous_interaction_id` when stored interaction valid
- Exact Google-side retention period **not verified** in this audit — treat as provider-managed until confirmed with Google documentation / account settings

**Recommendation before privacy-sensitive rollout:** evaluate `store=false` on staging with conversation context reconstruction via HASEM state only.

---

## 13. FLUTTER

**Contract verified** (`apps/hasim_finance`):
- Sends: `question`, `locale`, `conversation_id`
- Header: `Idempotency-Key` (UUID per send)
- Does **not** send: `workspace_id`, `user_id`, `role`, `model`, `previous_interaction_id` (workspace from session header)

**Tests (11 passed):**
- `assistant_screen_test.dart` — submit, error, RBAC gates, unavailable period
- `idempotency_header_test.dart`
- `finance_workspace_switch_test.dart`

**Gaps:** 409/429/503/retry/offline UX not exhaustively widget-tested; no live-backend E2E.

---

## 14. ROLLBACK

**Procedure:**

```env
FINANCE_ASSISTANT_AGENT=false
```

**Verified behavior:**
- `AssistantController` routes to legacy `FinanceAssistantOrchestrator` (heuristic/LLM planner, not Interactions agent)
- Test: `FinanceAgentOrchestratorTest::test_feature_flag_keeps_legacy_planner`
- Conversations remain in DB; no migration rollback required
- API contract unchanged (`/api/finance/v1/assistant/ask`)

**Immediate rollback triggers:** cross-tenant leak, wrong financial fact, uncontrolled cost, duplicate tool execution, repeated tool loop, severe Gemini instability.

---

## 15. REAL USER TEST RESULTS

Business question suite — **FakeGemini + tool fact assertions** (not live Gemini prose quality):

| Question theme | Tool / fact check |
|----------------|-------------------|
| Sales today/period | `sales.today`, `sales.period` |
| Expenses | `expenses.period`, `expenses.by_category` |
| Net/gross/COGS | `profit.net`, `profit.gross`, `profit.cogs` |
| Payroll | `payroll.period`, `payroll.total` (permission-gated) |
| Suppliers / inventory | `suppliers.payable`, `inventory.value` |
| Top products/customers | `products.top`, `customers.top` |
| Compare / summary / alerts | `compare.periods`, `finance.summary`, `finance.alerts` |

Live Arabic answer quality for full suite: **not evaluated** (only Test A live).

---

## 16. TEST COUNTS

### Finance Agent (PHPUnit `--filter=FinanceAgent`)

| | Passed | Failed | Errors | Skipped |
|---|--------|--------|--------|---------|
| **Full suite** | 31 | 0 | 0 | 2 |
| **Critical SQLite** (`phpunit.critical.xml`) | 11 | 0 | 0 | 2 |
| **Critical MySQL** (`phpunit.critical.mysql.xml`) | 13 | 0 | 0 | 0 |
| **Live Gemini** (`FINANCE_AGENT_LIVE_GEMINI=true`) | 1 | 0 | 0 | 0 |

Skipped on SQLite: `FinanceAgentMysqlConcurrencyTest` (2 tests — require MySQL/PG).

### Conversation-focused
Included in Finance Agent counts above (`FinanceAgentConversationTest`: 11 tests).

### Critical cross-cutting
Finance Agent tests are subset of critical suite; full critical SQLite **425+** tests green per Plan 5/6 (not re-run entirely in Plan 7).

### Flutter Finance (assistant-focused)

| | Passed | Failed | Errors | Skipped |
|---|--------|--------|--------|---------|
| **Assistant + idempotency + workspace switch** | 11 | 0 | 0 | 0 |

---

## 17. REMAINING LIMITATIONS

1. Live Gemini verified for **single-tool round-trip only**; B/C/D + live multi-tenant need staging.
2. `runtime:check --production-strict` **FAIL** locally (database queue/cache/session; no phpredis).
3. Redis-backed unique jobs / production queue not verified for AI path (AI ask is synchronous HTTP, not queued).
4. No dedicated Prometheus-style metrics (`ai_requests`, etc.) — observability via `Log::info` + `audit_logs`.
5. No automated alerting wired for Gemini error/token spikes.
6. Gemini `store=true` — Google retention not contractually verified.
7. AI multi-tool latency under production data volume unmeasured.
8. Flutter offline/retry/429/503 UX partially untested.
9. `apps/hasim_cashier` has **no** Finance Agent (confirmed) — Cashier AI remains separate.

---

## 18. PRODUCTION BLOCKERS

**Do NOT set `FINANCE_ASSISTANT_AGENT=true` globally until:**

| # | Blocker | Owner |
|---|---------|-------|
| 1 | `php artisan runtime:check --production-strict` **PASS** on staging/production (Redis queue/cache/session, phpredis/predis) | Ops |
| 2 | Live Gemini scenarios **B, C, D** + live **multi-tenant A/B** on staging | Engineering + Ops |
| 3 | Staged rollout (internal workspace → allowlist → controlled %) per Section 22 | Product/Ops |
| 4 | Monitor audit logs for token/cost/error rates during Stage 1–2 | Ops |
| 5 | Confirm Gemini model `gemini-2.5-flash` still supported in target Google project | Ops |

**Not blockers for staged internal testing** (code-ready): tenant isolation, idempotency, grounding, RBAC, rollback path.

---

## 19. ENABLEMENT STRATEGY

| Stage | `FINANCE_ASSISTANT_AGENT` | Audience |
|-------|---------------------------|----------|
| **0 — Current** | `false` (default) | All — legacy Finance Assistant |
| **1 — Internal** | `true` on internal/test workspace only | HASEM team |
| **2 — Allowlist** | `true` via workspace feature flag allowlist | Selected pilot tenants |
| **3 — Controlled** | Percentage / plan-gated rollout | Expanding production |
| **4 — General** | Default on for eligible plans | After metrics green 2+ weeks |

Use existing `WorkspaceFeatureFlag` + env flag combination; never default `true` in `.env.example`.

---

## 20. OBSERVABILITY & ALERTING

**Present today:**
- `Log::info('finance.assistant.ask', …)` — workspace, user, conversation, tools, duration, rounds
- `Log::warning` for Gemini failures, expired interactions
- `audit_logs.meta` — interaction_id, tokens, tool list, stop_reason (no API keys / raw prompts)

**Not present (document only):**
- Dedicated counters: `ai_requests`, `ai_success`, `gemini_latency`, `fallback_count`, etc.
- Pager/email alerts for Gemini 429 spikes or cost anomalies

**Recommended alerts (log/metric queries on staging+):**
- Gemini error rate > baseline
- 429 / rate_limited spike on `mobile-ai`
- `ai_tokens` consumption anomaly per workspace
- `FinanceAiConversationBusyException` / 409 conflict spike
- Grounding fallback / invented-number replacement rate

---

## 21. NO AI IN CASHIER

Confirmed: **no** references to `FinanceAgent`, finance assistant agent, or `/api/finance/v1/assistant` in `apps/hasim_cashier`. Cashier AI (if any) uses separate Cashier assistant stack.

---

## 22. PRODUCTION CONFIGURATION SNAPSHOT (this environment)

| Check | Result |
|-------|--------|
| `APP_DEBUG` | Not production-audit focus (local dev) |
| Redis | **FAIL** — extension not loaded |
| MySQL Finance Agent concurrency | **PASS** (13 tests) |
| `runtime:check --production-strict` | **FAIL** — production_queue/cache/session expect redis |
| `GEMINI_API_KEY` | Configured locally (server-side only) |
| `FINANCE_ASSISTANT_AGENT` | **false** |
| Rate limiting | Active on assistant route |
| Logs redacted | `AiSafety::redactSecrets`, no prompt storage by default |

---

## 23. FINAL ACCEPTANCE CRITERIA

| Criterion | Met? |
|-----------|------|
| Real Interactions API verified | **Partial** (Test A live; B–D fake only) |
| Native function calling | **Yes** |
| Multi-round | **Yes** (fake); live not run |
| Conversation persistent + tenant-safe | **Yes** |
| Tenant isolation adversarial | **Yes** (fake); live A/B not run |
| RBAC sensitive tools | **Yes** |
| Financial grounding | **Yes** |
| Idempotency | **Yes** (incl. MySQL concurrent) |
| MySQL critical concurrency | **Yes** |
| Redis production verification | **No** (local fail) |
| Runtime strict production check | **No** (local fail) |
| Flutter conversation + workspace switch | **Yes** |
| Cost / rate limits | **Yes** (config + tests); abuse load not run |

---

## 24. FINAL VERDICT

```
READY FOR CONTROLLED ROLLOUT
```

### Rationale
- Finance Agent architecture is **built, tested, and live-verified for core function-calling path** (Test A).
- Tenant isolation, idempotency, grounding, RBAC, conversation persistence, and rollback are **proven in automated tests** including MySQL concurrency.
- **`FINANCE_ASSISTANT_AGENT` remains `false`** — this verdict does **not** authorize global enablement.
- Staging must complete: production-strict runtime, live Gemini B/C/D, live multi-tenant, and Stage 1 internal workspace soak before allowlist expansion.

### Explicitly NOT chosen
- **READY TO ENABLE** — production-strict fails locally; live suite incomplete; rollout stages not executed.
- **BLOCKED** — no critical code defect found; prerequisites are operational/staging verification, not missing implementation.

---

*End of Plan 7 report.*
