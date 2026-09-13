# P1-C FINAL

**Date:** 2026-09-13  
**P0-1 … P0-8 / P1-A / P1-B:** CLOSED — not reopened.  
**Scope:** NotificationPreference, ApiIdempotencyKey, StoryView, AuthIdentity, and siblings that share the same direct-ID risk. No mass conversion to `WorkspaceScopedModel`.

## Status

**CLOSED**

Ownership is explicit for every target. HTTP routes were executed: forged IDs from workspace B under A are 403/404 and do not return B bodies. The same user in A and B does not collapse workspace boundaries. `ApiIdempotencyKey` can no longer replay another workspace’s cached JSON. `AuthIdentity` login is `provider + provider_user_id` and cannot authenticate as another user via client `workspace_id`. SQLite and isolated MariaDB (`hasem_p0_tenancy_test`) executed. Production `hasem` was not used.

---

## Ownership Matrix

**MODEL:** `NotificationPreference`  
**OWNER:** Authenticated user + optional workspace  
**TENANT BOUNDARY:** Product usage is **user + workspace** (`firstOrCreate` on `user_id` + `workspace_id`). Unique `(user_id, workspace_id)` already existed.  
**DIRECT API?:** No `{notificationPreference}` / `{id}` resource. Only `GET/PUT /api/mobile/v1/notification-preferences` for the current user and required workspace.  
**DIRECT JOB?:** No. `SendPushNotificationJob` takes `userId` + `workspaceId` and reads prefs through that pair.  
**GLOBAL?:** No  
**WHY:** Flags (messages/email/marketing) are per workspace so a multi-workspace user can mute A without muting B.  
**SECURITY TEST:** A read/update own; B cannot read A; other user + A header → 403; PUT `.../preferences/{id}` → 404.  
**FINAL CLASSIFICATION:** **SAFE** (workspace-specific, not over-scoped)

---

**MODEL:** `ApiIdempotencyKey`  
**OWNER:** User + workspace + route + client key  
**TENANT BOUNDARY:** Was `user_id + key + route` (unique). `workspace_id` was stored but **not used in lookup**. Same user, same `Idempotency-Key`, same path (`/api/mobile/v1/stories`) in B could replay A’s JSON.  
**DIRECT API?:** No. Header-only. `GET /idempotency/{id}` → 404.  
**DIRECT JOB?:** No  
**GLOBAL?:** No — replay tokens are not platform-global.  
**WHY:** Mobile/cashier POST/PUT/PATCH replay. Keys are replay tokens (not API secrets) but the **response body is tenant-sensitive**.  
**SECURITY TEST:** Same workspace + same key + same payload replays; same key + different payload → 409; same key in B does not return A body; other user same key is a new write.  
**FINAL CLASSIFICATION:** **SAFE** after fix (was **DANGEROUS**)

---

**MODEL:** `StoryView`  
**OWNER:** Child of `Story` + viewer `user_id`  
**TENANT BOUNDARY:** Unique `(story_id, user_id)`. `Story` is `WorkspaceScopedModel`. No `workspace_id` on the view — parent is the invariant.  
**DIRECT API?:** No `{storyView}` route. Access is `POST/GET /stories/{story}/view(ers)` after workspace-scoped story binding.  
**DIRECT JOB?:** No job accepts a StoryView ID. `ExpireStoriesJob` expires `Story` rows by time, not views.  
**GLOBAL?:** No  
**WHY:** A view is “this user saw this story.” Adding `workspace_id` would duplicate the parent.  
**SECURITY TEST:** A cannot show/view/list viewers of B’s story (404); list/analytics `views_count` do not include B; `/story-views/{id}` GET/DELETE 404.  
**FINAL CLASSIFICATION:** **SAFE** (parent-scoped)

---

**MODEL:** `AuthIdentity`  
**OWNER:** User (login identity)  
**TENANT BOUNDARY:** **User-global.** Unique `(provider, provider_user_id)`. No `workspace_id`. Client `workspace_id` is applied only after the user is resolved, and only if that user is an active member.  
**DIRECT API?:** None. `/api/auth/identities/{id}` GET/DELETE/POST → 404. No detach route.  
**DIRECT JOB?:** None  
**GLOBAL?:** User-global, not system-global. Lookup is never `AuthIdentity::first()` without provider + subject.  
**WHY:** One Google subject is one Laravel user across all workspaces.  
**SECURITY TEST:** Subject A logs in as user A even if `workspace_id` is B’s; B’s identity row is unchanged; already-authenticated OAuth callback does not attach/rebind.  
**FINAL CLASSIFICATION:** **SAFE** (user-global)

---

**Sibling reviewed (not mass-scoped):** Laravel `notifications` (mark-read is `$user->notifications()->whereKey()`). `DevicePushToken` already closed in P0-6. No other model shared this IDOR pattern.

---

## Findings Before Fix

- **DANGEROUS:** `IdempotencyService::find()` / `updateOrCreate()` ignored `workspace_id`. Unique was `(user_id, key, route)`. Mobile paths are workspace-header-based, so A and B share the same URL. Same user in two workspaces could receive A’s cached response in B.
- **REVIEW:** Notification preferences allowed a null workspace if context was missing. Product already stored per-workspace rows.
- **SAFE (proven, unchanged models):** StoryView via Story scope; AuthIdentity via provider subject + unique; no `first()`/`last()` user fallback on identities.
- No Cache:: keys for these four models. Idempotency is DB-backed. Cashier Google tickets are UUID cache, not these tables.

---

## Changes

- `IdempotencyService` + `EnsureIdempotency`: lookup/store include `workspace_id`. Skip replay/store if workspace context is missing. `request_hash` of method + path + raw body; mismatch → 409.
- Additive migration `2026_09_13_170000_p1c_idempotency_workspace_bound.php`: `request_hash`; replace unique with `(user_id, workspace_id, key, route)`. MariaDB requires dropping/re-adding FKs before dropping the old unique (error 1553).
- Notification preference HTTP handlers call `requireWorkspace()` so updates never write a null-workspace row.
- Tests: `tests/Feature/Feature/Security/P1CRemainingTenantIdorTest.php`.

Not converted to `WorkspaceScopedModel`: NotificationPreference, ApiIdempotencyKey, StoryView, AuthIdentity.

---

## API / IDOR

| Request | Result |
| --- | --- |
| User in A reads/updates prefs | Own A flags |
| Same user in B | Own B flags; A unchanged |
| User B + `X-Workspace-Id: A` prefs | 403 |
| `PUT .../notification-preferences/{id}` | 404 |
| Same Idempotency-Key in B after A story create | New B story; A body not returned |
| Forged B `Story` id under A | 404 (show/view/viewers) |
| `/story-views/{id}`, `/idempotency/{id}`, `/auth/identities/{id}` | 404 |

---

## Jobs

- `SendPushNotificationJob`: requires `workspaceId`; checks active membership; `shouldNotify(user, workspaceId)`. Forged pair (user B, workspace A) is a no-op; does not take a preference ID.
- `ExpireStoriesJob`: expires stories by timestamp under an explicit scheduler bypass; does not accept StoryView IDs; does not delete other-tenant views as a side path from a forged view ID.
- No AuthIdentity jobs.

---

## Cache / Idempotency

Replay store is the `api_idempotency_keys` row, not `Cache::`. Key dimensions are now **user + workspace + route + client key**. Payload fingerprint prevents silent reuse with a different body. No notification-preference, story-analytics, or AuthIdentity response cache was found.

---

## Multi-workspace User

One user, workspaces A and B:

- Prefs: update A (`messages=false`) does not overwrite B.
- Idempotency: key `shared-key-k` in A replays A; the same key in B creates B’s own result.
- Stories: A list/viewers/counts exclude B.
- Auth: identity remains the same user; workspace selection is membership-checked after login.

---

## SQLite

PASS: 82  
FAIL: 0  
SKIPPED: 0  
ERRORS: 0  

Includes P1-C (7) + requested regression (FailClosed, WorkspaceIsolation, RolePermission, Authentication, MobileAuth, ApiKey, WebhookReplay, PaymentWebhook, AIService isolation) + MobileConversation / MobileStories / FinanceFlutterAuth / CashierGoogleSocialAuth.

---

## MariaDB/MySQL

PASS: 82  
FAIL: 0  
SKIPPED: 0  
ERRORS: 0  

Database: isolated `hasem_p0_tenancy_test` via `phpunit.mysql.xml` after `php tests/bin/mysql-p0-probe.php`. Production `hasem` was not used.

---

## Remaining Review

- Linking a **new** Google subject to an existing user by matching email (`User::firstOrCreate(['email' => ...])`) is existing product account-linking, not workspace IDOR. Documented; not changed.
- Idempotency-Key values are replay tokens. They are not treated as API secrets, but stored response bodies are tenant-bound after this fix.
- `ExpireStoriesJob` still expires due stories across tenants under the existing scheduler bypass (P0 stories behavior). It does not take a forged StoryView ID.

P0 and P1-A/P1-B were not reopened.
