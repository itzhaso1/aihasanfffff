# Hasim Chat — Local-First Architecture Audit

**Date:** 2026-10-05  
**Phase:** A — audit only. No application code, dependencies, Laravel, or database changes.  
**App:** `apps/hasim`  
**Database file:** `hasim_inbox.sqlite` in the application-support directory  
**Engine:** Drift `^2.26.0` + `sqlite3` / `sqlite3_flutter_libs`, raw SQL, `schemaVersion` **3**  
**Not used:** `drift_dev` (the inbox database comment says code generation is blocked by this app's analyzer/SDK set)

Independent Worker stays outside `workspace_users`. This audit does not merge worker identity into company membership, and it does not restore Templates, Sellers, Cart, Checkout, Marketplace, or Storefront.

---

## 1. Current local database architecture

There is already one SQLite database. It is an inbox read model plus a text-message outbox, not a general local-first store.

| Piece | Role |
| --- | --- |
| `InboxDatabase` | Single Drift executor. Schema applied in `beforeOpen`. File, memory, and test-file openers. |
| `inbox_database_platform_io.dart` | `NativeDatabase.createInBackground` on `hasim_inbox.sqlite`. |
| `inbox_database_platform_web.dart` | Web executor. Same database type. |
| `InboxLocalStore` | Only DAO. Every query takes `workspace_id`. |
| `OutboxWorker` | One processor. Sends pending text messages. |
| `ConversationRepository` | Only repository that reads and writes this database. |
| `HiveInboxMigrator` | One-time import of old Hive inbox boxes, then deletes those boxes. |
| `PrefsStore` | `SharedPreferences`: workspace id, theme, wallpaper, bubble color, per-chat theme, API base override, push device id/workspace/token. |
| `SecureStore` | `flutter_secure_storage` key `hasim_access_token` only. |
| `SecureChatLockStore` | Chat-lock secret in secure storage, not SQLite. |
| `InboxSession` | In-memory generation + active workspace so a stale request cannot commit. |
| `WorkerRepository` | In-memory engagements cache only. Cleared on isolation-key change. |

Startup in `main.dart` opens this database before `runApp`, runs the Hive import (30s timeout), and injects that instance. If open/migration throws, the process falls back to an **in-memory** database for that launch, so persistence is skipped until the next successful open.

There is no second SQLite, no second Drift database, and no Drift table classes. `inboxDatabaseProvider` defaults to memory and `main.dart` overrides it with the file database.

Transactions exist (`InboxDatabase.transaction`). The atomic path today is optimistic text send: message row + outbox row. Conversation and message upserts run statement by statement, not one transaction per page.

`PRAGMA foreign_keys = ON` is set. The four tables do not declare foreign keys.

## 2. Existing tables

Schema version 3. Fresh installs create the current shape. Upgrades: v1→v2 adds `inbox_messages.updated_at`; v2→v3 adds conversation local-read columns. Indexes are recreated on every open.

### `inbox_conversations`

Local integer `id`, plus `workspace_id` + `server_id` (unique together).

Stored: channel, status, external id, `ai_enabled`, last message time, unread, `server_unread_count`, `local_read_at`, `local_read_last_message_at`, muted, archived, customer id/name/phone/email, last message preview and server id, `updated_at`, `cached_at`.

Not stored, although `ConversationModel` has them: `customer_priority`, `payment_status`, `review_status`, `classification_source`, `handoff_status`, `handoff_reason`, `assigned_user_id`, `assigned_user_name`, `can_reply`. `overlayingLocalInbox` only overlays unread, mute, archive, and last-message preview. A local paint therefore drops classification and handoff until the next API payload is held in memory.

No `sync_state`, `deleted_at`, or `server_version` column. Removal of a conversation on the server is not represented; old rows stay.

### `inbox_messages`

`workspace_id`, `conversation_server_id`, nullable `server_id`, nullable `client_id`, direction, type, content, `ai_generated`, `created_at`, user name, customer name, `attachments_json`, `local_state` (`pending` / `sending` / `sent` / `failed`), `cached_at`, `updated_at`.

Unique partial indexes on `(workspace_id, server_id)` and `(workspace_id, client_id)`.

`MessageResource` sends `worker_engagement_id`. `MessageModel` does not read it, and the table does not store it. Attribution that survives locally is `ai_generated`, `direction`, and `user_name`.

`getMessages` orders by `created_at ASC` and applies `LIMIT 300` (`_messagePageLimit`). That returns the **oldest** 300 rows, not the newest page. Once a thread grows past 300 cached rows, a cold local open can hide the latest messages.

Attachments are JSON metadata (id, kind, name, mime, size, duration, status, download/preview URLs). File bytes are not in SQLite. `cached_network_image` is a separate image cache.

### `inbox_outbox`

`workspace_id`, `client_id` (unique per workspace), `operation_type`, `conversation_server_id`, JSON `payload`, `state`, `attempts`, `next_attempt_at`, `last_error`, timestamps.

The only operation constant is `send_text`.

### `inbox_sync_state`

`workspace_id` + `resource` unique, nullable `cursor`, `updated_at`.

`ConversationRepository.list` and `messages` **write** the first-page Laravel cursor here. Nothing in the app **reads** `getSyncCursor`. The stored cursor is the next page cursor of a list request, not a change-feed token.

### Indexes

- `inbox_conversations (workspace_id, server_id)` unique
- `inbox_conversations (workspace_id, last_message_at)`
- `inbox_messages (workspace_id, server_id)` unique where `server_id` is not null
- `inbox_messages (workspace_id, client_id)` unique where `client_id` is not null
- `inbox_messages (workspace_id, conversation_server_id, created_at)`
- `inbox_outbox (workspace_id, client_id)` unique
- `inbox_outbox (workspace_id, state, next_attempt_at)`
- `inbox_sync_state (workspace_id, resource)` unique

Conversation search, channel, unread, and archive filters are applied in Dart after `SELECT *` for the workspace. There is no FTS index.

## 3. Existing DAOs and repositories

DAO: `InboxLocalStore` only. No generated Drift DAOs.

| Repository | Local persistence |
| --- | --- |
| `ConversationRepository` | SQLite read, upsert, outbox enqueue, fallback to cache when the first page fails |
| `AuthRepository` | None. Token stays in `SecureStore`. Last workspace id stays in `PrefsStore`. |
| `WorkspaceRepository` | None |
| `HomeRepository` | None. Unread overlay can be adjusted from inbox SQLite via `overlayHomeUnread`. |
| `SearchRepository` | None. Always `GET /search`. |
| `EmailRepository`, `CampaignRepository` | None |
| `ContactRepository`, `ContactGroupRepository` | None |
| `ChatCatalogRepository` | None |
| `StoryRepository` | None |
| `NotificationRepository` | None |
| `WorkerRepository` | Process memory for engagements only |
| `Plan` / `Channel` / `Branding` / `Customer` / `Ai` repositories | None |
| `SessionRepository`, `DeviceRepository` | None. Push device id and token are in `PrefsStore`. |
| `CompanyWorkerDiscoveryRepository` | None |

Screens that already paint SQLite before the network:

- Conversation list: `_loadCachedThenRefresh` shows cached rows, then `refresh(silent: true)`.
- Chat: `_init` shows cached conversation and messages, then refreshes. Empty cache still waits on the API with `loading: true`.
- Changing filter, channel, search, or classification calls `refresh()` and sets `loading: true` even when matching rows are already local.
- `loadMore` / `loadOlder` wait on the API. They do not read a local older page.

Other feature controllers load from the API and hold the result in Riverpod memory. Offline relaunch loses them.

## 4. Existing outbox

`OutboxWorker` is in-app, durable, and inbox-specific.

- Enqueue is atomic with the optimistic message (`enqueueTextSend`). `clientId` is required and is the idempotency key.
- Claim sets state to `sending` so a second flush cannot take the same row (`claimOutbox`).
- Connectivity (`connectivity_plus`) triggers flush. It is treated as a hint. Flush also runs when the worker starts and after send from `ConversationsController`.
- Backoff: 2s, doubling, capped at 300s. Auto attempts stop at 12, then the row is `failed` with a far `next_attempt_at`.
- Permanent HTTP failures: 400, 401, 403, 404, 409, 422. 401 is treated as permanent for that row; there is no auth-refresh path inside the worker.
- Success: `completeOutbox` writes `server_id`, keeps `client_id`, sets `local_state` to sent, deletes the outbox row.
- `MessageReconciler` matches client id, then server id. It does not match on content.
- Workspace guard: if `InboxSession.workspaceId` differs from the row, the row is deferred, not sent.
- Logout calls `InboxLocalStore.clearAll()` (conversations, messages, outbox, sync state) and clears the access token. Pending outbox does not survive logout.
- `clearWorkspace` deletes that workspace's four table sets. It is available; switching workspace does **not** call it. Other workspaces stay on disk and are filtered by `workspace_id`.

Not in the outbox: attachments, classification, archive, mute, handoff, product edits, contacts, email drafts, stories, settings, worker profile, work-session actions.

## 5. Existing sync

What exists is cache-aside, not a sync engine.

1. UI asks the repository.
2. Repository calls Laravel (`per_page` 20 conversations, 30 messages) with an optional **page** cursor.
3. The returned page is upserted.
4. If the first page throws and cache is non-empty, the repository returns cache and `nextCursor: null`.
5. A failed later page does not replace the list; `loadMore` just clears `loadingMore`.
6. API failure does not delete local rows.

`RealtimeService` is a hybrid of polling and the API client. Push (`PushService`) registers a device token. It does not write notifications or messages into SQLite. Opening the notification center still fetches `/notifications`.

Laravel conversation and message lists use cursor pagination (`cursorPaginate` on `last_message_at, id` and message `id`). There is no `updated_since`, sync token, or mutation-ack endpoint on the mobile inbox.

Idempotency for text send already exists: body `idempotency_key` and header `Idempotency-Key`.

## 6. Current API-heavy screens

These wait on the network before they can show durable data. In-memory Riverpod state helps only inside the same process.

| Surface | First paint |
| --- | --- |
| Login / register / session restore | Network for `/auth/me` and workspace list. Token and last workspace id are local. Profile JSON is not. |
| Home | `GET /home` and `/unread` |
| Global search | `GET /search` only |
| Email list and detail, campaigns | API |
| Contacts and contact groups | API |
| Catalog list, product, categories, digital pools, catalog settings | API |
| Stories and viewers | API |
| Notifications and preferences | API |
| Channels, plan, branding, AI settings, AI employees | API |
| Company worker discovery | API |
| Worker profile, skills, languages, experiences, documents, verification | API |
| Worker engagements, capabilities, guides | Memory cache after first fetch, then API |
| Work sessions, earnings, payouts, disputes | API. Session clock is displayed from server timestamps, not stored as truth. |
| Customer drawer | `GET /customers/{id}` |
| Sessions / devices | API |

Inbox is the exception, and only for the last successfully cached page of the current workspace.

## 7. Entities requiring local persistence

Mobile read models, not a copy of Laravel tables. Each mutable row needs `server_id`, `workspace_id` when the row is company-scoped, `updated_at`, `sync_state`, and `deleted_at` when the server can remove it. Local integer primary keys stay internal. They are not substitutes for `server_id`.

| Entity | Local need | Offline mutation |
| --- | --- | --- |
| Session profile (id, name, type, email) | Startup without waiting for `/auth/me` | No. Token stays in secure storage. |
| Workspaces the company user belongs to | Picker and last selection | Switch is local selection. Membership list is server-owned. |
| Worker inbox workspaces | Derived from engagements, not from `workspace_users` | No membership row. |
| Conversations | Already partial | Read local. Sensitive writes stay server-first (see conflicts). |
| Messages + attachment metadata | Already partial | Text send is already offline-safe. |
| Customers / contacts / groups | List and search | CRUD can be outbox if the mobile API already accepts the write. |
| Emails, attachments metadata, drafts, campaigns | List and open | Draft save can queue. Send and cancel stay online. |
| Products, variants, categories | List, search, detail | Create/update/delete can queue. Server reconciles. |
| Digital pools | Metadata only | No secret payload. |
| Digital units | id, type, status, expiry, safe metadata already returned by `ChatCatalogDigitalUnitResource` | Do not store codes, secrets, or credentials. |
| Stories / views | Short-lived cache | Create/delete may queue. Expired stories are not a durable archive. |
| Notifications | Inbox of notifications | Mark-read can queue. |
| Notification preferences | Server-backed settings | Queue. |
| Channels / connection status | Settings list | Connect/disconnect stay online (OAuth and secrets). |
| AI employees / skills | Read model for settings screens | Server-authoritative writes. |
| App appearance | Already in `PrefsStore` | Local-only. Do not move tokens into SQLite to “unify” storage. |
| Worker profile, skills, languages, experiences | Worker-owned, keyed by worker profile id | Profile edits may queue. Documents and verification submit stay online. |
| Engagements, capabilities, guides | Read model | Accept/reject stay online. |
| Work sessions | Read model of server timestamps | Start/break/resume/end stay online. Flutter must not invent duration. |
| Earnings, payouts, disputes | Read model, `amount_minor` + currency | Dispute create can stay online. Amounts are never computed on device. |

Do not add local tables for finance, POS, GL, cart, checkout, marketplace, storefront, templates, or sellers.

## 8. Proposed SQLite schema

Extend `InboxDatabase`. Do not open a second file.

Keep the four current tables. Add columns with `ALTER TABLE` the same way v2 and v3 did.

**v4 — account shell**

- `local_account`: server user id, display name, email, account type (`company_user` | `independent_worker`), cached_at. No password, no access token, no refresh token.
- `local_workspaces`: server workspace id, name, role label for company users. For a worker, rows are engagement workspaces only (`source = engagement`), never a fake membership.
- `local_settings`: key, owner (`device` | `user` | `workspace`), workspace_id nullable, json value, `origin` (`local` | `server`), updated_at, sync_state.

Appearance keys can stay in `PrefsStore`. Duplicating them into SQLite is optional and not required for startup speed.

**v5 — inbox completeness**

Add to `inbox_conversations`: customer priority, payment status, review status, classification source, handoff status, handoff reason, assigned user id, assigned user name, can_reply, sync_state, deleted_at.

Add to `inbox_messages`: `worker_engagement_id` nullable, sync_state. Keep `ai_generated`. Do not infer worker vs human vs AI from anything else.

Change the message read to the newest page (`ORDER BY created_at DESC LIMIT`) and keep older rows only when the user has paged them. Cap per conversation (proposed: latest 300, plus any still-pending outbox rows). This cap is a retention rule, not a silent delete of pending sends.

Generalize `inbox_outbox` so `conversation_server_id` is nullable and payload carries `entity_type` + `entity_server_id`. Existing `send_text` rows remain valid.

Add `sync_checkpoint`: workspace_id nullable, owner_type (`workspace` | `worker`), owner_id, resource, cursor, updated_since, updated_at. Stop writing unused page cursors into `inbox_sync_state` as if they were incremental tokens. Either reuse that table with a resource naming rule or replace its use in a later migration after readers exist.

**v6 — catalog, contacts, customers**

`local_customers`, `local_contacts`, `local_contact_groups`, `local_contact_group_members`, `local_products`, `local_product_variants`, `local_product_categories`.

Each: `workspace_id`, `server_id` unique together, `updated_at`, `sync_state`, `deleted_at`, fields the current mobile resources already return.

`local_digital_pools`, `local_digital_units`: metadata columns only, matching `safeMetadata()` (`filename`, `mime_type`, `byte_size`, `checksum`, `version`, `label`) plus status and expiry.

**v7 — email**

`local_emails`, `local_email_attachments` (metadata and URLs, not bytes), `local_email_drafts`, `local_email_campaigns` as read models. Draft body is user content, not a provider secret.

**v8 — stories and notifications**

`local_stories`, `local_story_views`, `local_notifications`, `local_notification_preferences`.

Stories are cache with `expires_at`. They are not an offline archive of media files.

**v9 — worker**

Keyed by `worker_profile_server_id`, not by inserting the worker into `local_workspaces` as a member.

`local_worker_profile`, skills, languages, experiences, engagements, capabilities, guides, session snapshots, earning periods, earning lines (`amount_minor`, `currency`), payouts, disputes.

No identity-document images, no review notes, no IBAN, no wallet balance.

**v10 — channels and remaining server settings**

`local_channels` (connection status and display name only), plus server-backed settings rows in `local_settings` for catalog, AI, and notification preferences.

Search indexes, when a table is large enough to matter: index `(workspace_id, name)` and message `(workspace_id, conversation_server_id, created_at DESC)`. FTS only if profiled search on cached conversations/contacts/products is slow. Do not full-scan on the UI isolate for the 1,000-conversation target.

## 9. Migration plan

Stay on `schemaVersion` and the existing `beforeOpen` ladder. `inbox_migration_test.dart` already builds a raw v1 file with `sqlite3` and opens it through `InboxDatabase.file`.

Rules:

- Additive `ALTER` and new tables only.
- Never delete the user file on upgrade.
- Re-run `CREATE INDEX IF NOT EXISTS` after upgrade, as today.
- One schema version per phase that changes tables, so a failed upgrade has a known `versionBefore`.
- Test each step: fresh install, previous version → new version, and a row that is missing a new nullable column.
- Hive import stays a one-time side path. Do not revive Hive as a store.

Approximate new versions on top of v3: **7** (v4 through v10). That is about **7 migrations**, not one migration per table and not a new database.

v5 should also fix the message read order. That fix is code, not a table rewrite. If old databases already contain more than 300 messages per thread, reading newest-first is enough; no destructive purge in the migration itself.

## 10. Workspace isolation strategy

Company data rows carry `workspace_id`. Every query keeps the current explicit `workspace_id` argument. Switching workspace changes `InboxSession` and `activeWorkspaceIdProvider`. It must not `clearAll`. It must not show the previous workspace's streams.

Pending mutations keep `workspace_id` on the outbox row. The worker already refuses to send a row when the session workspace differs. A future sync engine must do the same, and must not replay a mutation after the user has switched.

Independent Worker:

- Not a `workspace_users` member. Local account type stays `independent_worker`.
- Inbox rows use the engagement's workspace id, which the app already selects through `WorkerInboxWorkspaceController`.
- Worker profile, skills, sessions, earnings, and disputes use worker profile id + engagement id. They are not stored as company settings.
- Logout already wipes the inbox file. A later account switch must wipe or partition by account id so worker A cannot read worker B's cached earnings. Today the file is single-user and cleared on logout; that assumption must be kept explicit when the file starts holding worker compensation.

`clearAll` on logout remains correct. Do not clear on workspace switch.

## 11. Sync strategy

One engine, evolved from `OutboxWorker` plus the existing repository upserts. Repositories do not each grow a sync loop.

Pull:

- Conversations and messages already have cursor **pages**. Use those to fill the local window. Do not call that incremental sync.
- True incremental sync needs `updated_since` or a sync cursor. Laravel does not provide it today (section 15).
- Until that contract exists, background refresh re-fetches the first page and upserts. That is safe and bounded (`per_page` ≤ 50 on the server). It is not a full history download.
- Older messages load on scroll, then upsert. The local read returns the newest window plus pending sends.

Push:

- Keep text send idempotency.
- Add further outbox types only when the operation is safe to delay (section 12).
- One flush loop, exponential backoff, connectivity as a hint, claim-before-send, workspace check, auth expiry stops the queue instead of deleting it.

UI:

- There is no Drift `watch` stream today. Controllers use one-shot futures plus `inboxLocalRevisionProvider`.
- Phase C should expose a workspace-scoped query stream (or keep the revision bump, but fire it after every upsert) so the list updates from SQLite after a background page lands.
- A screen with local rows must not set `loading: true` in a way that replaces them with a spinner.

Startup must not sync every domain. Sync the active workspace's conversation first page, then idle-schedule the rest.

## 12. Conflict strategy

Not last-write-wins as a global rule.

| Domain | Rule |
| --- | --- |
| Messages | Append plus idempotency key. Match client id, then server id. Never match on text. Human stays human, worker stays worker (`worker_engagement_id` + `ai_generated = false`), AI stays `ai_generated`. |
| Conversation list fields that are user-local (mute, archive, read) | Local unread overlay already exists and must not be cleared when the server page is older than `local_read_at`. |
| Classification, handoff, assignment | Server authoritative. Do not outbox them until the local row can store the fields and a failed write can roll back the optimistic value. Checkpoint still treats several of these as membership-only on the worker inbox; do not invent worker APIs. |
| Products | Server authoritative. Optimistic local row with `sync_state = pending`, then replace from the server body. Unique `(workspace_id, server_id)` plus client mutation id before the server id exists. |
| Settings | Per key. Local-only appearance never syncs. Server-backed keys carry a version or `updated_at` and show a conflict if the server rejects. |
| Worker engagement, session, earnings | Server authoritative. Cache is readable offline. Do not queue session start/end or earning math. Duration and money come back from Laravel. |
| Permanent rejection | Keep the outbox row as `failed`, surface the server message, do not drop the user's text. |
| API down | Keep SQLite. Show the local data and a sync error. Do not empty tables. |

## 13. Security strategy

SQLite is not a secret store.

Already correct:

- Access token in `SecureStore` (`flutter_secure_storage`), not SQLite.
- Chat lock in secure storage.
- Digital unit API already strips secrets (`ChatCatalogDigitalUnitResource::safeMetadata`).

Must stay out of SQLite: passwords, access tokens, refresh tokens, OAuth client secrets, channel credentials, digital codes, identity-document files, worker `review_notes`.

Existing gap, not in SQLite: `PrefsStore` stores `push_device_token` in `SharedPreferences`. Leave it out of the new tables. Moving it to secure storage is a separate hardening change, not part of Phase A.

Media bytes stay in the image cache and on-demand download, not in SQL.

## 14. Performance strategy

Targets from the phase brief: 1,000 conversations, 10,000 messages, 500 products, 1,000 contacts.

Current risks:

- Conversation list loads every row for the workspace, then filters in Dart.
- Message read takes the oldest 300.
- Upserts are per row, outside a page transaction.
- Opening the app always runs the Hive existence checks.
- Prefetch warms up to 4 threads from the network when cache is empty.
- Polling realtime and outbox flush are separate from a scheduler, so a rebuild must not add another timer. Controllers must not `GET` on every rebuild; today refresh is tied to user actions and workspace changes, which is acceptable, but filter changes still block on the network.

Phase H measures cold start, warm start, list open, chat open, search, and product list on those sizes. Startup sync is the conversation first page only.

## 15. Required Laravel sync contracts

Do not add these in Phase A. Later, only if a phase cannot stay correct on the current mobile API.

Already usable, no change:

- `GET /conversations` cursor page, filters, `per_page` max 50.
- `GET /conversations/{id}` and `GET .../messages` cursor page, `per_page` max 50.
- `POST .../messages` with `idempotency_key` and `Idempotency-Key`.

Not on the mobile API today:

- `updated_since` or a sync token for conversations, messages, catalog, contacts, email, notifications.
- A deleted-id set so the device can tombstone rows missing from a page.
- Idempotency keys on catalog, contacts, and other mutations (text send is the one confirmed path).

Smallest later addition, per resource, workspace-scoped, authorized, paginated, tested:

1. Inbox changes since cursor (conversations, then messages).
2. Tombstones or `deleted_at` on those payloads.
3. Idempotency on the next outbox mutation that is not text send.

Worker session and compensation endpoints stay as they are. No new idle endpoint. No earnings calculator.

## 16. Recommended implementation phases

Phase A is this document. Stop here.

| Phase | Work |
| --- | --- |
| B | Persist account type, workspace list, and last workspace in the same database or keep prefs for appearance and only add the account/workspace snapshot. Restore session UI from local data, then refresh `/auth/me`. No token in SQLite. |
| C | Finish conversations and messages: missing columns, newest-window read, query updates into the list, generalise the outbox without a second worker, dedupe on client id. |
| D | Products, contacts, customers, email metadata and drafts. |
| E | Stories cache and notification center. |
| F | Worker read models, isolated from company membership. Sessions and money remain server-authoritative. |
| G | Channel status and remaining server-backed settings. |
| H | Indexes, startup budget, dataset tests, one sync scheduler. |

Each later phase: tests, `flutter analyze`, migration from the previous schema version, short report. No single commit that converts the whole app.

---

## Phase A answers

- SQLite is already in place: one Drift file, schema v3, inbox tables, text outbox, unused sync-cursor writes.
- Extend `InboxDatabase` / `InboxLocalStore` / `OutboxWorker`. Do not add a package, a second file, or a second sync stack.
- Missing: every domain except a partial inbox, conversation classification/handoff columns, newest-message reads, a cursor reader, a real incremental contract, and a stream from SQLite back to the UI.
- About **7** additive schema upgrades (v4–v10) on the current database.
- First product domain to finish: **conversations and messages** (Phase C), after the small account/workspace snapshot (Phase B) so startup and workspace switches have a local scope.
- No blocker that prevents Phase B. The architectural limit is that Laravel cursor pagination is not a change feed, and the outbox only knows `send_text`. Incremental sync and non-text offline writes wait until those are designed on top of this database.
