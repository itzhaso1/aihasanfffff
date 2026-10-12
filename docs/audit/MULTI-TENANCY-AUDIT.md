# MULTI-TENANCY-AUDIT

Audit date: 2026-09-13  
Priority: maximum. Isolation is `workspace_id` + `WorkspaceScopedModel` + membership middleware + Spatie teams.

---

## 1. Design (good)

| Control | File |
| --- | --- |
| Tenant table | `workspaces` — `App\Models\Workspace` |
| Membership | `workspace_users` — `WorkspaceUser` (**not** globally scoped) |
| Context | `App\Support\Tenancy\WorkspaceContext` |
| Resolve | `ResolveWorkspaceContext` + `WorkspaceResolverService` |
| Membership gate | `EnsureWorkspaceMembership` |
| Feature gate | `EnsureFeatureAccess` |
| Model scope | `WorkspaceScopedModel` |
| Route binding | scoped `resolveRouteBinding` |
| Cross-write guard | creating/updating throws `Cross-workspace write attempt` when context **is** set |
| Tests | `tests/Feature/Feature/Tenancy/WorkspaceIsolationTest.php` plus many finance IDOR 404s |

Cross-workspace access is specified to **404**, not 403 (`AGENTS.md`).

---

## 2. Tenant Isolation Findings

### Tenant Isolation Finding — AUD-TEN-001
- Severity: Critical
- Location: `app/Models/WorkspaceScopedModel.php` global scope
- Evidence: No `workspace_id` predicate when context is null. Jobs (`SendWhatsAppMessage`, `ProcessAIResponse`, `SyncEmailInboxJob`, billing commands) must set context themselves.
- Attack Scenario: A scheduled command or webhook handler loads `Customer::find($id)` or `FinanceInvoice::query()->…` without binding workspace → returns any tenant’s row.
- Impact: Cross-tenant PII, invoices, chats
- Recommendation: Fail closed for HTTP; require explicit `forWorkspace($id)` on console; add lint/test that jobs set context
- Effort: M
- Priority: P0

### Tenant Isolation Finding — AUD-TEN-002
- Severity: High
- Location: `app/Models/EmailLog.php`, `EmailAttachment.php`, `EmailCampaignRecipient.php`, `EmailWebhookEvent.php`
- Evidence: These extend `Model`, not `WorkspaceScopedModel`. `EmailLog` is fillable including `workspace_id`.
- Attack Scenario: A controller that lists `EmailLog::query()` without a workspace constraint can dump recipients across tenants.
- Impact: Email PII leakage
- Recommendation: Scope or always constrain in one service; add IDOR tests
- Effort: S
- Priority: P0

### Tenant Isolation Finding — AUD-TEN-003
- Severity: High
- Location: `DevicePushToken`, `StoryView`, `ApiIdempotencyKey`, `NotificationPreference`, `AuthIdentity`
- Evidence: Unscoped models that can hold user/device/tenant-adjacent data
- Attack Scenario: Push fan-out or idempotency lookup by raw key without workspace
- Impact: Cross-device notification or replay across tenants if keys collide poorly
- Recommendation: Add workspace_id + scope where the row is tenant-owned
- Effort: M
- Priority: P1

### Tenant Isolation Finding — AUD-TEN-004
- Severity: Medium
- Location: `InvoiceService` uses `Customer::withoutGlobalScopes()->where('workspace_id', $workspace->id)`
- Evidence: Correct pattern — but proves developers routinely disable the scope. A missed `where workspace_id` after `withoutGlobalScopes()` is an IDOR.
- Attack Scenario: Developer copies the withoutGlobalScopes call and forgets the where
- Impact: Cross-tenant customer attach on invoice
- Recommendation: Helper `Customer::inWorkspace($id)` that cannot omit the constraint
- Effort: S
- Priority: P1

### Tenant Isolation Finding — AUD-TEN-005
- Severity: Medium
- Location: Public menu `Route::get('/menu/{workspace:slug}')`
- Evidence: Resolves workspace by public slug, not membership
- Attack Scenario: Enumeration of slugs → customer PII on menu / order injection
- Impact: Tenant data exposure at the marketing/QR edge
- Recommendation: Only published POS workspaces; generic 404
- Effort: S
- Priority: P2

### Tenant Isolation Finding — AUD-TEN-006
- Severity: Low (control)
- Location: Platform admin update skip in `WorkspaceScopedModel::updating`
- Evidence: `if (auth('platform_admin')->check()) return;`
- Attack Scenario: Compromised platform admin = all tenants (expected). Session fixation on that guard is catastrophic.
- Impact: Platform-wide breach
- Recommendation: Hardware 2FA, IP allowlist, no default password
- Effort: M
- Priority: P1

---

## 3. What looks solid

- Finance invoice create validates customer/supplier belong to the same workspace.
- Finance API and cashier API apply `workspace.resolve` + `workspace.member`.
- Mobile attachment download uses **signed** URL (`routes/mobile.php`).
- Copilot/search tests claimed grounded isolation (`docs/HASEM_IMPLEMENTATION_STATUS.md`).
- Public assistant is documented as not accepting arbitrary `workspace_id` (`docs/security.md`) — **re-verify `WebsiteAssistantService` at runtime**.

UNKNOWN — Requires runtime verification: every artisan command path, every job `handle()`, and every `DB::table()` query.
