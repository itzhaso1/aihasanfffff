# P0-6 FINAL

**Date:** 2026-09-13  
**P0-1 / P0-2 / P0-3 / P0-4 / P0-5:** CLOSED — not reopened.  
**Scope:** Tenant isolation for EmailLog, email/message attachments, `device_push_tokens`, and proven unscoped tenant-owned siblings (`EmailCampaignRecipient`).

## Status

**CLOSED**

HTTP, download/preview/delete, signed-URL, multi-workspace, and forged-ID job tests were executed on SQLite and isolated MariaDB. Workspace A cannot read, update, delete, download, preview, or obtain a signed URL for Workspace B resources. The same physical FCM token can exist on A and B; logout/register in A does not revoke or overwrite B. Production database `hasem` was not used. Migration is additive. No first/default workspace backfill.

P0-7 was not started.

---

## Findings Before Fix

| Resource | Before | Attack |
| --- | --- | --- |
| `EmailLog` | Had `workspace_id` but extended `Model`, not `WorkspaceScopedModel`. No tenant HTTP surface originally; new admin-like routes would have been IDOR-ready. | Cross-tenant `find($id)` / route binding |
| `EmailAttachment` | Child of scoped `EmailMessage`, no `workspace_id`. Blade used `Storage::disk('public')->url()`. | ID-only download of PII/files |
| `EmailCampaignRecipient` | Child of scoped campaign; `SendCampaignRecipientJob` used `find($id)` without workspace. | Forged recipient ID sends B’s campaign from A’s job |
| `DevicePushToken` | Unique `(user_id, token)`. Register in A overwrote the B row for the same FCM string. Send could fan out across workspaces. | Multi-workspace overwrite + cross-workspace push |
| `MessageAttachment` | Already `WorkspaceScopedModel`. Signed URL could be generated before current-workspace proof. | Unauthorized signed URL |
| Route binding | `ensureWorkspaceContextForBinding` trusted leftover ambient context. A 404 on B’s ID left context A, so the next request (including owner B or shared user on B) bound against A. | Sequential-request / Octane stale-tenant bind |
| `SendPushNotificationJob` | `workspaceId === null` delivered to every active token for the user. | Ambiguous fan-out to B |
| `revokeByToken` | Revoked every row with that FCM string for the user. | Logout/revoke A could kill B (unused path, still a landmine) |

---

## Inventory

### SAFE

- `EmailMessage`, `EmailAccount`, `EmailCampaign`, `EmailContact` — already `WorkspaceScopedModel`
- Finance / contract attachments — already scoped (out of this ticket; finance logic not changed)
- `WebhookEvent` (payments/WhatsApp) — already constrained on P0-1; not reopened
- `DevicePushToken` access paths after this ticket — register/revoke/send require workspace + membership; not converted to `WorkspaceScopedModel` (see semantics below)

### REVIEW

- **`EmailWebhookEvent`** — provider inbound child (`email_log_id`). No tenant HTTP. **C / parent-linked.** Do not invent `workspace_id`. Updates go through `ResendWebhookService` with `withoutGlobalScopes()` + provider id, then query-builder status write.
- **Legacy `email_attachments.disk = public`** — existing files were **not** mass-moved. New IMAP/uploads use `local` (`storage/app/private`). Download still authorizes workspace before bytes.
- **`config/filesystems.php` `local.serve = true`** — Laravel local disk can generate serve URLs. Authorization still happens before any email/message download. Do not treat disk privacy as auth.
- **`NotificationPreference`** — `user_id` + optional `workspace_id`, not `WorkspaceScopedModel`. Used for push category flags. Not a file/PII log. Left unchanged.
- **`CentralEmailService` outbound attachments** — still reads `public_storage_path` on the `public` disk for branded/system mail payloads. Not a tenant download endpoint.
- **Orphan `email_attachments` / `email_campaign_recipients`** with null `workspace_id` after backfill (parent missing). Nullable by design. Scoped queries hide them. No first-workspace assignment.

### DANGEROUS (fixed this ticket)

- `EmailLog` — tenant-owned (nullable `workspace_id` for system mail)
- `EmailAttachment` — tenant-owned (backfill from `email_messages`)
- `EmailCampaignRecipient` — tenant-owned (backfill from `email_campaigns`)
- `DevicePushToken` lifecycle — workspace-specific **rows** (unique `user_id + token + workspace_id`)
- `MessageAttachment` signed download — authorize current workspace before URL/bytes
- Stale route-model-binding context after 404

---

## Classification

| Model | Class | Workspace rule |
| --- | --- | --- |
| `EmailLog` | **A** tenant-owned | `WorkspaceScopedModel` + `workspace_id`. Null allowed for system mail (password reset). Same null-workspace create/update exception as `AuditLog`. |
| `EmailAttachment` | **A** (direct HTTP) | `WorkspaceScopedModel` + `workspace_id`. Parent `EmailMessage` is the backfill source, not the only access path. |
| `EmailCampaignRecipient` | **A** (job ID access) | `WorkspaceScopedModel` + `workspace_id`. Job filters `whereKey` **and** `workspace_id`. |
| `MessageAttachment` | **A** (already) | Signed route + `findOwned(id, workspaceId)` before stream. |
| `DevicePushToken` | **A rows, not global-scoped model** | Workspace-specific device registration. Same physical FCM token may exist on A and B. Not `WorkspaceScopedModel`: tokens are user+device+workspace, and user-global unique was the bug. |
| `EmailWebhookEvent` | **C** provider/system | Bound to provider message / `EmailLog`. No tenant route. |
| `NotificationPreference` | **REVIEW** | Per-user (optionally per-workspace) flags. No download/IDOR surface in this ticket. |

`DevicePushToken` semantics chosen: **workspace-specific rows**, not user-global overwrite, not “delete B on logout from A”.

---

## Models Changed

- `app/Models/EmailLog.php` → `WorkspaceScopedModel`
- `app/Models/EmailAttachment.php` → `WorkspaceScopedModel` + `disk` + `assertSafeStoragePath()` / `deleteStoredFile()`
- `app/Models/EmailCampaignRecipient.php` → `WorkspaceScopedModel` + `workspace_id` fillable
- `app/Models/WorkspaceScopedModel.php` — EmailLog null-workspace write exception; **route binding re-resolves from the current request** when a user is present (stale context after 404 no longer wins)
- `app/Models/DevicePushToken.php` — already had `workspace_id`; unique constraint moved in migration (not a scoped model)

---

## Migrations

`database/migrations/2026_09_13_140000_scope_email_attachments_recipients_and_push_tokens.php`

Additive only:

- `email_attachments.workspace_id` nullable FK + index; `disk` default `public`
- Backfill attachments from `email_messages.workspace_id` only (skip if parent missing)
- `email_campaign_recipients.workspace_id` nullable FK + index
- Backfill recipients from `email_campaigns.workspace_id` only
- Drop `device_push_tokens` unique `(user_id, token)`
- Add `device_push_tokens_user_token_workspace_unique` + workspace/user/revoked index

No history rewrite. No row deletes. No `first()` / default workspace assignment.

---

## Storage / Download Security

| Surface | Disk | Path | Auth before bytes |
| --- | --- | --- | --- |
| Email attachment download/preview/delete (web) | row `disk` (`local` for new) | `file_path` (reject `..`, absolute) | Route binding + `assertSameWorkspace` + safe path, **then** `Storage::download` |
| Email attachment download (mobile) | same | same | Membership workspace + `workspace_id` match + safe path, **then** download |
| Message attachment signed download | `local` | `path` | Signature + `workspace` query + `findOwned` + `runForId` + conversation policy, **then** stream |
| `MessageAttachmentService::downloadUrl` | — | — | Throws unless current workspace matches. **No** `temporaryUrl` / signed route before that |

Executed (not status-only):

- A downloads own bytes (`alpha`)
- A download/preview/delete B → **404**, B file still on disk, row remains
- Forged signed route `attachment=B, workspace=A` → **404**, response body does not contain `chat-b-secret`
- Unauthorized `downloadUrl(B)` in context A throws
- Path `../secrets/passwd` → **404**
- Mobile A download B → **404**; mobile A download own → bytes `alpha`

Blade `workspace/emails/show.blade.php` uses the download route, not a public `/storage` URL.

---

## Multi-workspace User Behavior

Shared user is an active **manager** on A and B, same FCM string `fcm-shared-device` as two rows.

| Case | Result |
| --- | --- |
| Shared user + session A reads `logA` | **200** |
| Shared user + session A reads `logB` | **404** |
| Owner B / shared user + session B reads `logB` | **200** |
| Leftover context A, then session B + `logB` | **200** (binding follows request, not leftover) |
| Shared user downloads A attachment, not B | bytes / **404** |
| Register token in A | creates A row only; does not create/overwrite B |
| Delete B token id while header is A | **404**, B `revoked_at` null |
| `SendPushNotificationJob(..., workspaceA)` | `activeTokensForUser` contains A token, not B |
| Job for owner A + workspace B | no-op (not an active member of B) |
| Login A, register `fcm-session-a`, logout A | B row for `fcm-shared-device` still unrevoked |

Logout revokes push rows by **personal access token id**, not by FCM string.

---

## Job Security

| Job | Forged / mismatch | Result |
| --- | --- | --- |
| `SendCampaignRecipientJob(recipientB, workspaceA)` | `withoutGlobalScopes` + `workspace_id` filter | no-op; recipient stays pending |
| `SendEmailMessageJob(messageB, workspaceA)` | same | sender mock must not receive `send`; B file untouched |
| `EmailLog::find(logB)` inside `runFor(A)` | global scope | `null` |
| `SendPushNotificationJob` without `workspaceId` | fail-closed | returns immediately; no fan-out |
| `SendPushNotificationJob(user, workspaceA)` | membership + token filter | cannot target B token |

Fail-closed (P0-1) is unchanged: no-context query/create/update/delete on newly scoped models throws `MissingWorkspaceContextException`.

---

## API / Route Model Binding

New web routes (email feature + membership):

- `GET/DELETE workspace/emails/logs/{emailLog}`
- `GET .../attachments/{emailAttachment}/download|preview`
- `DELETE .../attachments/{emailAttachment}`

Mobile:

- `GET /api/mobile/v1/emails/attachments/{emailAttachment}/download` (declared before `emails/{emailMessage}`)
- Devices require resolved workspace; revoke filters `user_id + workspace_id + id`

Forged `{emailLog}` / `{emailAttachment}` / `{attachment}` / `{device}` from B while current workspace is A → **404**. No metadata, URL, or bytes.

---

## SQLite

Command:

```bash
php artisan test --testdox --filter="FailClosedWorkspaceTenancy|WorkspaceIsolation|RolePermission|AuthenticationTest|MobileAuthAndWorkspace|ApiKeyAuthorization|ApiKeyManagement|WebhookReplay|PaymentWebhook|TenantEmailAttachmentPushIsolation|EmailInboxHub|ResendWebhook|JobIsolationExecution|MobileConversationApi"
```

PASS: **94**  
FAIL: **0**  
SKIPPED: **0**  
ERRORS: **0**  
Assertions: **353**  
Engine: SQLite `:memory:` (`phpunit.xml`)

P0-6 file alone: **14** tests, **64** assertions, all pass.

---

## MariaDB/MySQL

Prepare: `php tests/bin/mysql-p0-probe.php`  
Output: `status=READY`, `test_database=hasem_p0_tenancy_test`, `configured_database=hasem` (app `.env`, **not** used by phpunit).  
Engine: MariaDB **10.4.32**. Host `127.0.0.1:3306`.

```bash
php vendor/bin/phpunit -c phpunit.mysql.xml --testdox --filter="FailClosedWorkspaceTenancy|WorkspaceIsolation|RolePermission|AuthenticationTest|MobileAuthAndWorkspace|ApiKeyAuthorization|ApiKeyManagement|WebhookReplay|PaymentWebhook|TenantEmailAttachmentPushIsolation|EmailInboxHub|ResendWebhook|JobIsolationExecution|MobileConversationApi"
```

PASS: **94**  
FAIL: **0**  
SKIPPED: **0**  
ERRORS: **0**  
Assertions: **353**  
Config: `phpunit.mysql.xml` → `DB_DATABASE=hasem_p0_tenancy_test`

Production / app database `hasem` was not migrated or queried by this suite.

---

## Existing Data / Backfill

| Table | Strategy |
| --- | --- |
| `email_logs.workspace_id` | Already present. No invented ownership. Null remains system mail. |
| `email_attachments.workspace_id` | Deterministic copy from `email_messages.workspace_id`. Orphans stay `null`. |
| `email_campaign_recipients.workspace_id` | Deterministic copy from `email_campaigns.workspace_id`. Orphans stay `null`. |
| `device_push_tokens` | Existing `(user_id, token)` unique dropped so A and B can share one FCM string. No token rows reassigned. |

Inventory of production orphans was **not** run against `hasem` (forbidden). After deploy, count `WHERE workspace_id IS NULL` on the two new columns if needed. Do not assign them to the first workspace.

---

## Remaining Review

- Legacy email files may still sit on the **public** disk (`/storage/...`) until a dedicated, consented move. Downloads for those rows still require workspace auth.
- `EmailWebhookEvent` stays provider-global.
- `NotificationPreference`, `ApiIdempotencyKey`, `StoryView`, `AuthIdentity` were not mass-scoped.
- `journal.*` unused; not this ticket.
- `local` disk `serve => true` is framework default; do not generate serve URLs for tenant files without the same authorize-first rule.

P0-7 not started.
