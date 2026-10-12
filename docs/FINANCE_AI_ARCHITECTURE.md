# Finance AI Architecture

Finance AI is a workspace-scoped accounting assistant. It is independent of Inbox AI: it does not use Inbox agent classes, prompts, memory, or tool registries.

## Runtime

```text
POST /api/finance/v1/assistant/ask
  → FinancialAssistantOrchestrator
  → FinanceAgentOrchestrator (canonical Gemini Interactions loop)
  → FinanceAssistantToolRegistry (allowlist)
  → FinanceAssistantToolExecutor (security gateway)
  → Finance domain services
```

When the Gemini provider is unavailable and `finance_assistant.planner_fallback` is true, the request falls back to the deprecated planner. Tool execution still passes through the same security gateway.

## Security gateway

Trusted `FinanceAgentSecurityContext` (workspace, user, conversation, correlation id) is created by application code.

Each tool call:

1. Registry lookup (unknown tools do not execute)
2. Risk/access policy (current tools are read-only)
3. Argument bounds and identity-key stripping (`workspace_id`, `*_id`, permissions)
4. Per-tool run budget
5. `FinanceAccess` permission check
6. Schema validation
7. Domain execution under `WorkspaceContext`
8. Model-facing minimization via `FinanceAgentResultMinimizer`

Metadata for all registered tools lives in `FinanceAgentToolSecurityCatalog`.

## Context and prompt

`FinanceAgentStructuredContext` is fingerprintable. Conversation history is labeled untrusted and is not financial truth. Numbers must come from tools. `FinanceAgentGrounding` checks answer figures against tool results using `Money` normalization (grouped digits and Arabic-Indic digits).

## Configuration

See `config/finance_assistant.php`: `agent_enabled` (default true), `planner_fallback`, tool round/call caps, and `security.*` limits.

## Not in scope

Inbox AI, Commerce marketplace, and write/mutation tools are outside this agent.
