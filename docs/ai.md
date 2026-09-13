# AI

## Surfaces

- Workspace AI settings UI (`workspace/ai-settings`).
- Public/member assistant endpoint `POST /assistant/chat` (`AssistantController` + `WebsiteAssistantService`).
- POS menu AI chat routes (separate, table/general menu).
- Authenticated workspace reply (`AIService`, `/api/ai/reply`, mobile suggest/summarize).
- Finance copilot is keyword/SQL over the current workspace — not an LLM tool runtime.
- Entitlement feature `ai` + meters `ai_usage` / `ai_tokens`.

## Providers

Configured via `config/ai.php` and `services.google_ai_studio` / OpenAI settings. Default provider often Google AI Studio; missing key falls back to deterministic Arabic helper replies.

Server allowlists provider IDs, model IDs, temperature (0–1), and `max_tokens` (`AI_MAX_TOKENS_CAP`). Clients cannot select an arbitrary expensive model.

## Public vs tenant data

- Guests on `/assistant/chat` receive public plan/onboarding help only. Products, invoices, customers, and other tenant rows are not attached. `workspace_id` in the body or `X-Workspace-Id` does not bind a tenant for guests.
- Authenticated members may receive their own catalog products after membership-checked workspace resolution.
- POS menu AI is bound to the public `{workspace:slug}` route and active menu items only.

Public traffic uses RateLimiter plus a cache bucket (`ai-public-bucket:*`). It does not increment a paid workspace `ai_usage` meter.

## Logging / privacy

Default `AiLog` rows store metadata only (workspace, conversation, model, token counts, outcome, product name list). Full prompts and raw provider payloads are stored only when `AI_LOG_STORE_CONTENT=true` (off in production default), and secrets are redacted.

`AI_LOG_RETENTION_DAYS` is optional. If unset, the product does **not** claim a legal retention period — that decision is pending owner/counsel.

## Honest status

Assistant is usable for onboarding/FAQ; quality depends on configured provider keys. Do not treat fallback replies as live LLM output. There is no LLM function-calling tool runtime.
