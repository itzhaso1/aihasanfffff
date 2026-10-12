# Hasim Chat — Local-First Phase B

**Date:** 2026-10-05  
**Scope:** Account snapshot, workspace snapshot, workspace switching, conversations, messages, and the existing text outbox.  
**Database:** the existing Drift file `hasim_inbox.sqlite`. Schema **v3 → v4**. No second database and no new package.  
**Laravel:** unchanged. Laravel remains the source of truth for authorization and business rules.

Independent Worker stays outside `workspace_users`. This phase does not store worker engagements as company memberships, and it does not touch products, email, contacts, stories, channels, AI, notifications, or commerce.

---

## 1. Database migrations

`InboxDatabase.schemaVersion` is **4**.

Fresh installs create the v4 shape. An existing v1, v2, or v3 file upgrades in place:

- v1 → v2: `inbox_messages.updated_at`
- v2 → v3: local-read columns on conversations
- v3 → v4: conversation read-model columns, message attribution columns, `local_account`, `local_workspaces`

Rows, outbox operations, and messages already on disk are kept. New conversation columns use defaults (`customer_priority = normal`, `handoff_status = none`, `sync_state = synced`). Logout still calls `clearAll()`, which now also deletes the account and workspace snapshots.

## 2. Account local model

`local_account` is a single row (`id = 1`):

- server user id, name, email, phone, avatar, locale, timezone
- participant type and worker profile id
- current workspace server id
- `cached_at`

No password, access token, refresh token, or OAuth secret. The access token stays in `SecureStore`.

Startup with a saved snapshot:

1. Read the secure token.
2. Read `local_account` and paint `AuthState` immediately.
3. Refresh `/auth/me` in the background.
4. If that refresh fails, keep the snapshot and the token.

If there is a token but no snapshot, startup still waits for `/auth/me`. If that call fails, the session is cleared, same as before.

## 3. Workspace local model

`local_workspaces` stores `server_id`, name, type, slug, `is_owner`, and `role`.

The mobile workspace contract sends `is_owner`, not a role string. `role` is stored only when the JSON already contains `role` or `membership_role`. SQLite is not an authorization source.

An Independent Worker snapshot stores the participant type and an empty workspace list. Engagement workspaces stay on the existing worker inbox selector and are not written as memberships.

## 4. Conversation local model

`inbox_conversations` now also stores classification, handoff, assigned user, `assigned_worker_engagement_id`, `assigned_participant_type`, `can_reply`, `sync_state`, and `deleted_at`.

List queries filter in SQL by `workspace_id`, archive, unread, channel, classification, and search, ordered by `last_message_at`, limited to 1,000 rows. Indexes cover `(workspace_id, server_id)`, `(workspace_id, archived, last_message_at)`, and `(workspace_id, unread_count)`.

## 5. Message local model

`inbox_messages` now also stores `worker_engagement_id`, `sender_user_id`, `participant_type`, `sync_state`, and `client_created_at`.

`participant_type` is the server value when present. If it is absent, it is derived only from `ai_generated` and `worker_engagement_id`: AI stays AI, worker stays worker, otherwise human.

Opening a chat reads the newest 300 messages and returns them oldest-to-newest. Older rows stay in SQLite and load on scroll. Startup does not read the whole history.

## 6. Repository changes

`ConversationRepository` remains the boundary. Screens do not open the database or the outbox.

- `watchConversations` and `watchLatestMessages` re-query after each committed write.
- `list` and `messages` still upsert what Laravel returns.
- If the API fails and a local page exists, the repository returns that page and sets `lastReadFromCache`.
- Archive, mute, classification, handoff, assign, takeover, resolve, and resume AI still call the API first. After success, the local row is updated and the list/chat read it back from SQLite.
- Those actions stay online-only. They need the server to accept the write. A failed call does not change the local row.

The reactive stream is a query stream on this Drift database. The project does not use `drift_dev`, so the stream is emitted after commits on the same executor rather than generated `Table.watch()`.

## 7. Outbox changes

`OutboxWorker` is still the only processor, and `send_text` is still the only operation. No second outbox was added.

Enqueue is still atomic with the pending message. Claim, backoff, permanent 4xx failure, workspace mismatch, and client-id dedupe are unchanged. A successful send writes `server_id`, keeps `client_id`, and deletes the outbox row. The same server message is not inserted twice.

Sending a text message now also updates that conversation's last-message preview locally.

## 8. Sync behavior

There is still no Laravel change feed. Sync for this phase is:

- paint SQLite first
- refresh the current cursor page in the background
- upsert that page
- let the local query update the UI

Triggers: startup, workspace switch, opening the list, opening a chat, pull-to-refresh, filter/search changes, returning online (existing outbox connectivity hint), after a successful text send, and after a successful conversation action.

A silent refresh does not reset the load-more cursor. A new filter or a fresh open does.

## 9. Offline behavior

With a snapshot and cached inbox:

- the shell shows the account and workspace without `/auth/me`
- the conversation list shows cached rows without waiting for `GET /conversations`
- an open chat shows the latest cached messages
- a text send is stored as `pending` and queued

If the API fails, local rows are not deleted. The list shows the saved data and a short sync error when the failure is visible to the controller. Home paints the local unread count, then refreshes `/home` in the background.

## 10. Workspace isolation

Every conversation and message query takes `workspace_id`. Switching workspace:

1. stops the previous realtime watch
2. points `InboxSession`, preferences, and `local_account.current_workspace_server_id` at the new workspace
3. clears the on-screen list so the previous workspace is not shown
4. then calls `POST /workspaces/switch`

If the server rejects the switch, the previous workspace is restored. Outbox rows keep the workspace they were created in. The worker only flushes the active workspace. Logout wipes the inbox file, the snapshot, and the token.

## 11. Conflict and reconciliation

Messages match `client_id`, then `server_id`. They are not matched on text. Idempotency for text send is the existing `Idempotency-Key` header and body field.

Conversation classification, handoff, assignment, archive, and mute stay server-authoritative. The local row is updated only after the API accepts them. Local read state still prevents an older server unread count from undoing a local mark-read.

## 12. Tests

`test/local_first_phase_b_test.dart` covers account round-trip, workspace isolation, newest-message order, worker/AI attribution, outbox workspace scope, a 1,000-conversation / 10,000-message query, startup from the snapshot before `/auth/me`, and a conversation title painted from SQLite before the list request returns.

Existing inbox migration, outbox, tenancy, unread, and conversation UI tests were re-run.

## 13. Performance

The list query is bounded and indexed. The chat query returns at most 300 messages. A local test inserted 1,000 conversations and 10,000 messages, then read the list and one thread in under 1.5 seconds. Image bytes are still outside SQLite.

## 14. Laravel changes

None.

## 15. Limitations

- The first launch after install, or after logout, still waits for the API because there is no snapshot yet.
- Archive, mute, classification, handoff, assign, takeover, resolve, and resume AI are online-only.
- Attachments are still uploaded online. Only text send is in the outbox.
- `role` is empty unless the workspace payload already includes it.
- There is no incremental change feed. Each refresh re-reads the current page, not "everything updated since".
- Home email and notification counts still come from `/home`. Only the conversation unread count is local.
- Two Flutter tests were already failing before this phase and were not changed: `app_config_test` (production host is `http://127.0.0.1:8000`) and `inner_chrome_corner_test` (overlay size 5×4 instead of 4×4).

## 16. Required future sync contracts

Not built in this phase:

- `updated_since` or a sync cursor for conversations and messages
- tombstones for conversations deleted on the server
- idempotency for mutations other than text send

---

## Phase B answers

- **Yes, when a snapshot or cached inbox exists.** Account, current workspace, the conversation list, and the latest messages render from SQLite, then refresh in the background. A brand-new install still bootstraps from the API once.
- **Yes.** A text message is inserted locally as `pending` with a client id, shown immediately, and sent through the existing outbox. Success writes the server id. Failure stays retriable or permanent without a duplicate row.
- **Yes.** Queries, unread counts, and outbox rows are scoped by `workspace_id`. Switching workspace hides the previous list before the server call returns, and a rejected switch rolls back. Logout clears the local session.
- **Blocking calls that remain for this phase:** the first `/auth/me` when no snapshot exists; `POST /workspaces/switch` after the UI has already switched; archive, mute, classification, and handoff until the server accepts them; opening a chat that has no cached messages.
- **`flutter analyze`:** no issues in `lib` and `test`.
- **Tests:** full Flutter suite **449 passed, 2 failed**. The failures are the two pre-existing cases named above (`app_config_test`, `inner_chrome_corner_test`).
- **No new architectural blocker.** The limit that remains is the missing Laravel change feed, so refresh is still a bounded page upsert rather than incremental sync.
