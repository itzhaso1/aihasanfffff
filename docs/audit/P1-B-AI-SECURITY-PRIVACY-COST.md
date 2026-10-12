# P1-B FINAL

**Date:** 2026-09-13  
**P0-1 … P0-8:** CLOSED — not reopened.  
**P1-A:** CLOSED — not reopened.  
**Scope:** AI security, prompt injection, authorization, privacy, cost controls, AI logging. No UI redesign. No AI architecture rewrite.

## Status

**CLOSED**

Public AI cannot resolve another tenant or attach private finance/customers/invoices. Authenticated AI is bound to the current membership workspace; the model is not an authorization source. There is no LLM tool/function-calling runtime. Default `AiLog` stores metadata only. Public traffic is rate-limited before the provider call and does not consume paid workspace `ai_usage`. Model/provider/`max_tokens` are server-allowlisted. Duplicate usage events do not double-charge. SQLite and isolated MariaDB (`hasem_p0_tenancy_test`) executed. Production database `hasem` was not used.

Server-side boundaries were proven with a fake provider. Refusal text from an LLM was not treated as a control.

---

## AI Surface Inventory

| Surface | Auth | Workspace | Public | Rate | Cost / quota | Model | Max tokens | Prompt cap | Tools | Data | Tenant scope | Writes | Full prompt log | Full response log | PII stored | User system-prompt injection | After P1-B |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `POST /assistant/chat` → `WebsiteAssistantService` | Optional session | Guests: none. Members: membership-resolved only | Yes (guests) | 8/min + 40/day + route 20/min | Public cache bucket only; no workspace meter | Server allowlist | Capped (`AI_MAX_TOKENS_CAP`) | 800 chars | None | Guests: public plans. Members: own catalog | Guests cannot bind tenant via body/header/prompt | None | No `AiLog` | No | Plans snapshot only | No (server prompt) | **SAFE** |
| `POST /menu/{workspace:slug}/ai-chat` (+ table token) → `PosMenuAiService` | Public | Route slug only | Yes | 8/min + 40/day + route 30/min | Public bucket; not paid workspace quota | Allowlisted from workspace settings | Capped | 800 chars | None | Active public menu items | Slug is the public identifier | None | No `AiLog` | No | Menu name/price/desc | Settings instructions not used as public system prompt | **SAFE** (cost REVIEW: platform provider spend still possible, rate-capped) |
| `AIService::generateReply` / `ProcessAIResponse` | Conversation workspace + feature `ai` | Conversation `workspace_id` | No | Caller-dependent | `ai_usage` / `ai_tokens` + idempotent event key | Allowlist | Capped | `AI_MAX_INPUT_CHARS` / instructions 2000 | None | Same-workspace products (name/price/stock) | `withoutGlobalScopes` + explicit `workspace_id` | Outbound message / existing WhatsApp only if inbound metadata already has phone ids | Default: no | Default: no | Product names in metadata | Workspace `AiSetting.instructions` (owner-configured, capped) | **SAFE** for isolation; **REVIEW** for owner instructions field |
| `POST /api/ai/reply` | Sanctum + membership + `update` conversation + feature `ai` | Server context (`X-Workspace-Id` / token) must match conversation | No | Route group | Workspace meters | Server | Capped | Via `AIService` | None | Conversation message + products | 404 on foreign conversation | Creates outbound AI message | Metadata only | Metadata only | Message content not stored by default | Same settings field | **SAFE** |
| Mobile `suggest-reply` / `summarize-conversation` | Sanctum + `view` + feature | Header workspace must match conversation | No | `throttle:mobile-ai` | Workspace meters | Server | Capped | Truncated; summarize limit 12 messages | None | Transcript / inbound text | 404 mismatch | Persist blocked | Metadata only | Metadata only | Transcript sent to model when feature on; not logged by default | Same | **SAFE** |
| Finance copilot web + `/api/finance/v1/copilot/ask` | `finance.view` + current workspace | `currentWorkspace()` / `clientWorkspace()` | No | Finance/write throttles | Not an LLM | n/a | n/a | 2000 question | Allowlisted **intents**, not LLM tools | Workspace SQL aggregates | Bound `workspaceId` | `create_invoice` is preview-only | n/a | n/a | Finance aggregates for authorized workspace | No | **SAFE** |
| `PUT /api/ai/settings` / web AI settings | Auth + membership | Current workspace | No | — | — | Remapped to allowlist | Cap 1024 | Instructions max 2000 | None | Settings row | Scoped `AiSetting` | Settings write | n/a | n/a | Instructions | Yes — owner/admin settings | **REVIEW** (intentional tenant config, capped) |
| `UpdateAIUsage` | Job | Constructor `workspaceId` | n/a | n/a | Idempotent `usageEventKey` | n/a | n/a | n/a | None | `AiLog` token sum | `withoutGlobalScopes` + id | Meter increment if `tokens > 0` | n/a | n/a | No | No | **SAFE** |
| Providers (`OpenAiProvider`, `GoogleAiStudioProvider`) | Server | n/a | n/a | HTTP timeout 30s | Caller | Allowlist only | Caller (already capped) | n/a | No function-calling API | Messages from services | n/a | HTTP to provider | Must not persist `raw` by default | Same | Provider traffic | No | **SAFE** |

**Audit classification (before → after):**

- **SAFE:** Finance copilot (already keyword/SQL); public website assistant guest path; providers after allowlist + timeout.
- **REVIEW → SAFE:** Authenticated website assistant (membership resolver only); API reply (was global `exists:conversations,id`); POS menu (slug + public items + rate/caps).
- **DANGEROUS → SAFE:** `AIService` full prompt/raw `AiLog`; API reply IDOR; client-influenced model/`max_tokens`; `UpdateAIUsage` retry double-count when `tokens > 0`.

No LLM tools exist. There is no arbitrary URL/SQL/shell/file tool.

---

## Public AI

`POST /assistant/chat` (guests):

- Does not attach products, invoices, customers, payroll, KYC, or private documents.
- Ignores `workspace_id` body and `X-Workspace-Id` for guests (`WebsiteAssistantService::resolveProductContext`).
- Client `model` / `provider` / `max_tokens` / `temperature` are `prohibited` (422 before provider).
- 800-character cap; 8/min and 40/day RateLimiter **before** the model; route throttle 20/min.
- Public usage bucket: `ai-public-bucket:assistant:{date}` (cache). Does not increment workspace `ai_usage`.

POS menu AI:

- Workspace comes only from `{workspace:slug}` (explicit public identifier).
- Active menu items only (name, category, size, description, price, currency). No stock, no private catalog products, no other workspace items.
- Client cannot send `workspace_id` / model / provider / token fields.
- Same layered rate limits (8/min, 40/day) before provider.

Proven: guest prompts including “ignore previous instructions”, foreign `workspace_id`, headers, and hidden JSON do not put workspace A/B private product or customer names into the model context.

---

## Tenant AI

Authenticated requests require:

1. Authenticated user  
2. Active workspace membership (`workspace.member` / policy pivot `status=active`)  
3. Feature/plan `ai` (`FeatureAccessService`)  
4. Conversation ability: `update` on `/api/ai/reply` (owner/admin/manager/agent); `view` on mobile suggest  

`workspace_id` in the body cannot select another tenant. Conversation workspace must match server context or the request 404s.

Proven:

- Manager/owner A → A products only (direct prompt, injected workspace id, SQL/URL text).  
- A requesting B conversation → 404.  
- Unauthenticated `/api/ai/reply` → 401.  
- Suspended membership → 403.  
- Feature flag `ai=false` → 403.  
- Staff (`membership_role=member`) cannot call `/api/ai/reply` (policy `update`).  

---

## Tools

**None.** `AiProviderInterface::generate()` accepts messages, model, temperature, `maxTokens` only.

Finance copilot intents are PHP `match` + scoped SQL. `create_invoice` returns `preview_action=invoice.create` and does not insert invoices (`PlatformCore` + P1-B).

`POST /api/ai/tools/admin` → 404.

No arbitrary URL fetch, SQL, shell, file path, or class invocation from model output.

---

## Prompt Injection

Influenced surfaces reviewed:

| Surface | Can model output authorize? | Control |
| --- | --- | --- |
| Product/menu queries | No | Server builds filters from conversation/slug workspace id |
| Tool arguments | N/A | No tools |
| HTTP / file / email / payments | No | No AI tools; WhatsApp outbound only if inbound metadata already has ids |
| Customer/invoice retrieval for LLM | Public: never. Tenant AI: products only, same workspace | Copilot uses bound `workspaceId` |
| System prompt | Tenant settings instructions (capped 2000) | Public prompt is server-owned |
| Developer instructions | Not client-supplied | `AiSafety` allowlists |

HTML from the model is stripped (`script`/`style` removed, then `strip_tags`) before JSON/Blade consumers get the string.

---

## Logging / Privacy

Default `AiLog` (`AI_LOG_STORE_CONTENT` default **false**):

- `workspace_id`, optional `user_id`, `conversation_id`, `message_id`
- `request_id` (header or generated UUID)
- provider/model, `prompt_chars`, `product_names`, outcome
- `tokens_used`, status, redacted `error_message`
- output `content_chars` only

Not stored by default: full `messages`, raw provider payload, prompt text, response text.

Opt-in debug (`AI_LOG_STORE_CONTENT=true`) stores redacted snippets only. Secrets matching `sk-`, `whsec_`, `Bearer`, `APP_KEY=`, `DB_PASSWORD=` are replaced with `[REDACTED]`.

`AI_LOG_RETENTION_DAYS` is optional and **unset by default**. This report does **not** claim a legal retention period. Retention/compliance is pending owner/counsel. No privacy-policy legal fact is asserted here.

Context sent to the model is minimized vs full Eloquent dumps: product/menu field lists, not passwords, API keys, tokens, webhook secrets, `APP_KEY`, or DB credentials.

---

## Rate / Cost Controls

| Control | Public assistant | POS menu AI | Workspace AI |
| --- | --- | --- | --- |
| Rate limit before model | Yes (429) | Yes (429) | Mobile/API throttles; usage pre-check |
| Per-user / workspace quota | Public bucket ≠ paid quota | Same | `ai_usage` / `ai_tokens` hard_block |
| Plan/feature gate | n/a (public FAQ) | n/a | Feature `ai` |
| Max input | 800 | 800 | Config + settings 2000 |
| Max output tokens | Server cap | Server cap | Server cap |
| Model allowlist | Server default only | Settings remapped | Settings remapped |
| Timeout | Provider 30s | Provider 30s | Provider 30s |
| Body size | Validation 800 | Validation 800 | Request rules |

Client cannot choose provider, expensive model, uncapped `max_tokens`, or temperature &gt; 1. Failed auth is not recorded as successful AI usage.

---

## Usage Accounting

- Meters are written for the conversation’s workspace only.
- Public/POS traffic does not increment workspace `ai_usage`.
- `AIService` claims `aiservice:{conversationId}:{messageId}` (or ephemeral hash) before `consumeUsage`.
- `UpdateAIUsage` with `tokens > 0` requires a stable `usageEventKey` (explicit or job UUID). Same key + retry → one consume.
- `tokens = 0` (current `AIService` dispatch) only refreshes the token-sum cache.
- Failed provider: log `status=failed`, `tokens_used=0`, meter unchanged.
- Second generation after `ai_usage` limit 1 throws `UsageLimitExceededException` without a second consume.

---

## Model Allowlist

`config/ai.php`:

- Providers: `google_ai_studio`, `openai`
- Models: configured Gemini flash IDs + `gpt-4o-mini`
- `max_tokens_cap` default 1024
- Unavailable/unknown provider → default if allowed, else exception (website assistant falls back; tenant AI returns a safe error string)

No silent upgrade to an unlisted expensive model.

---

## SQLite

PASS: 62  
FAIL: 0  
SKIPPED: 0  
ERRORS: 0  

Command: `php artisan test --testdox --filter="AIServiceWorkspaceIsolation|PlatformCore|AuthenticationTest|MobileAuthAndWorkspace|RolePermission|FailClosedWorkspaceTenancy|AiSafetyAndUsageTest|AiSecurityPrivacyCostTest|AssistantChatTest"`

Includes P1-B (18) + required regression.

---

## MariaDB/MySQL

PASS: 62  
FAIL: 0  
SKIPPED: 0  
ERRORS: 0  

Database: isolated `hasem_p0_tenancy_test` via `phpunit.mysql.xml` after `php tests/bin/mysql-p0-probe.php` (`status=READY`).  
Production `hasem` was not used.

Command: `php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml --testdox --filter="…same…"`

---

## Remaining Review

- Workspace `AiSetting.instructions` is an intentional owner-controlled system-prompt field (max 2000). It cannot change workspace id or invoke tools.
- Public assistant and POS menu can still spend **platform** provider credits; they are rate-capped, not unlimited, and do not burn tenant paid quota.
- Gemini HTTP `retry(2)` may repeat a provider call on transport failure; usage is counted only after a successful `AIService` generation.
- CI uses a fake provider. Live Gemini/OpenAI was not called. `AssistantChatTest` exercises the real HTTP route + fallback when no key is configured.
- Legal retention / PDPL wording is **not** claimed. Configure `AI_LOG_RETENTION_DAYS` only after owner/counsel decide.
- `ProcessAIResponse` may send WhatsApp when inbound metadata already contains `phone_number_id` and `wa_id`. That is the existing product workflow, not a new AI tool.

None of the above is an open cross-tenant or secret-logging hole.

P0 and P1-A were not reopened.
