# Inbox AI Trust Boundary

## Principle

The LLM is an **untrusted decision-maker** with **zero inherent authority**.

Customer content, memory, catalog text, and metadata are **DATA**. They are never **INSTRUCTIONS** or **AUTHORITY**.

## Trust zones

| Zone | Examples |
| --- | --- |
| A — Untrusted external input | Customer messages, catalog descriptions, memory values, metadata |
| B — AI processing | Prompts, model output, tool calls, arguments |
| C — Trusted application | `WorkspaceContext`, conversation/customer ownership, policies, config |
| D — Sensitive resources | Database, payments, secrets, external APIs |

The AI must not move from Zone B to Zone D without Zone C mediation.

## Tool execution path

```text
InboxAgentToolCall
  → Registry allowlist
  → Risk policy
  → Argument security (forbidden identity fields)
  → Schema validation
  → Run budget (per-tool + duplicate limits)
  → Tool business logic (workspace from context)
  → Result sanitizer
  → Model
```

## Tool metadata (required for new tools)

Every production tool must declare:

- `name`
- `input_schema`
- `risk_level` (`read`, `low`, `medium`, `high`, `critical`)
- `access_mode` (`read` | `write`)
- `requires_confirmation`
- `max_calls_per_run`
- Result sanitizer rules
- Audit/security events

Implement via `InboxAgentSecuredTool` + `InboxAgentToolSecurityProfile`.

## Read vs write

- **Read tools** (e.g. `search_products`) do not mutate business state.
- **Write tools** (future: orders, payments, refunds) require explicit confirmation architecture and must not execute in Phase 7.

## Confirmation policy

| Risk | Policy |
| --- | --- |
| read / low read | no confirmation |
| medium write | confirmation architecture (future) |
| high / critical | denied or explicit human confirmation (future) |

Natural-language customer confirmation is **not** sufficient for sensitive actions.

## Workspace isolation

Identity fields (`workspace_id`, `customer_id`, `conversation_id`, …) in model tool arguments are **rejected** (fail closed).

Trusted IDs come from `InboxAgentSecurityContext` built server-side.

## Memory trust model

Memory is customer-derived **untrusted context**. It must not grant admin/refund/access authority. Instruction-like and authority-like memory candidates are rejected.

## Prompt injection model

Prompt text explains trust boundaries for the model, but **enforcement is in PHP** (gateway, isolation, validation).

Do not block legitimate customer language via naive keyword bans on inbound messages.

## Secrets

Provider API keys and `.env` secrets must never appear in prompts, tool results, or customer-visible errors.

## Rate / cost limits

Agent loop enforces `max_iterations`, `max_tool_rounds`, cooperative timeout, per-tool call caps, and duplicate tool-call limits. Memory extraction is async and failure-isolated.

## Future network tools (SSRF)

Any future URL/network tool must enforce HTTPS, domain allowlists, block private/loopback/link-local/metadata IPs, validate redirects, and cap response size/timeouts. No generic `fetch_url` in current phases.

## Future action tools

Before production, tools like `create_order`, `refund`, or `update_customer` must implement authorization, ownership checks, confirmation workflow, audit logging, and sanitizers. They must **not** call models with raw IDs from untrusted arguments.
