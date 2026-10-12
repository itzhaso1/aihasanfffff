# AI-AUDIT

Audit date: 2026-09-13

---

## 1. Providers and entry points

| Component | File | Provider |
| --- | --- | --- |
| Manager | `app/Services/AI/AiProviderManager.php` | openai, google_ai_studio/gemini |
| OpenAI | `app/Services/AI/Providers/OpenAiProvider.php` | `config('services.openai.key')` |
| Workspace reply | `AIService` | conversation + 20 products |
| Public / website assistant | `WebsiteAssistantService`, `AssistantController` | Gemini/OpenAI + fallback |
| Finance copilot | `FinanceCopilotService` | **Keyword / SQL aggregates, not tool-calling LLM** |
| POS menu AI | `PosMenuAiService`, public `menu/.../ai-chat` | guest-capable |
| Usage | `FeatureAccessService` meters `ai_usage` / `ai_tokens`; `UpdateAIUsage` job | |

Keys: `GEMINI_API_KEY`, `OPENAI_API_KEY` in `.env.example`. Default provider google_ai_studio.

---

## 2. Can AI see another tenant?

`AIService` loads products with `where('workspace_id', $conversation->workspace_id)` and instructions “استخدم فقط بيانات نفس workspace”.  
`Workspace::withoutGlobalScopes()->find($conversation->workspace_id)` is used if scoped find fails — still by conversation’s id.

**If a conversation row were cross-tenant (see MULTI-TENANCY), AI would follow that workspace_id.** Isolation is only as strong as conversation integrity.

Public assistant: docs say no arbitrary workspace_id; stock omitted on public site. UNKNOWN — Requires reading every branch of `WebsiteAssistantService` at runtime with a crafted prompt.

Copilot: workspace SQL only; confirmation required for create-invoice preview; must not post/refund (`HASEM_MASTER_PLATFORM_SPEC.md` rule 6).

---

## 3. Findings

### AUD-AI-001
- Category: AI
- Severity: High
- Title: Prompt injection + catalog/PII in system prompt
- Location: `AIService` json_encode of products including stock/price
- Impact: Customer or attacker extracts inventory; jailbreak ignores instructions
- Recommendation: Minimize fields; separate retrieval; output filter
- Effort: M
- Priority: P1

### AUD-AI-002
- Category: AI
- Severity: High
- Title: Full prompt/response persisted
- Location: `ai_logs.input_payload` / `output_payload`
- Impact: GDPR/PII retention; prompt leak via backups
- Recommendation: Hash/redact; short TTL
- Effort: S
- Priority: P1

### AUD-AI-003
- Category: AI
- Severity: High
- Title: Unauthenticated public + QR-menu AI
- Location: `AssistantController`, `PosCustomerMenuController::askAi`
- Impact: Cost DoS; brand-damaging replies
- Recommendation: Budget + kill switch
- Effort: S
- Priority: P1

### AUD-AI-004
- Category: AI
- Severity: Medium
- Title: Users may think copilot is an LLM accountant
- Location: implementation status honesty #2
- Impact: Wrong business decisions
- Recommendation: UI label “مؤشرات من الدفاتر” not “ذكاء”
- Effort: S
- Priority: P2

### AUD-AI-005
- Category: AI
- Severity: Medium
- Title: Fallback discloses missing Gemini key
- Location: `WebsiteAssistantService` reason `missing_gemini_key`
- Impact: Config reconnaissance
- Recommendation: Generic fallback message
- Effort: S
- Priority: P2

---

## 4. Cost / rate / permissions

- Feature entitlements can block over-quota (`assertWithinLimit`).
- Public path has extra RateLimiter (8/min 40/day).
- Finance copilot requires `finance.view` via `CopilotClientController`.
- AI settings API: `/api/ai/settings` behind workspace member.

No hard dollar budget object was found (only token meters).

UNKNOWN — Requires runtime verification: whether provider keys are set; actual token spend; prompt-injection corpus.
