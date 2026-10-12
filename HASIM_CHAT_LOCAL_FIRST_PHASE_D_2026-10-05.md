# Hasim Chat — Local-First Phase D

**Date:** 2026-10-05  
**Scope:** stories and the notification center on the existing Drift database.  
**Schema:** v5 → v6. No second database and no Laravel change.

Independent Worker stays outside `workspace_users`. Products, contacts, email, channels, AI, and worker code were not redesigned.

---

## 1. Stories schema

`local_stories` stores the fields the strip and viewer already use: type, caption, text, background, mime, size, visibility, expiry, view count, author id/name/avatar, `is_mine`, `viewed_by_me`, and `deleted_at`.

Media URLs that look signed (`X-Amz-`, `signature=`, `expires=`) are not stored. Stable URLs are kept. File bytes stay out of SQLite. Existing image caching remains outside the database.

`local_story_viewers` stores user id, display name, avatar, and `viewed_at` only.

Indexes: `(workspace_id, created_at)` and `(workspace_id, expires_at)`.

## 2. Notification schema

`local_notifications` stores server id, type, title, body, a sanitized data map, `read_at`, and `created_at`.

Keys named secret, token, password, credential, or authorization are dropped from the payload. Deep-link ids such as `conversation_id` and `story_id` stay, because the existing route resolver already reads them.

`local_notification_preferences` stores the three flags the settings screen already edits: messages, email, marketing.

Indexes: `(workspace_id, created_at)` and `(workspace_id, read_at)`.

## 3. Stories local-first

The updates list reads non-expired, non-deleted stories from SQLite and paints them before `GET /stories`. If that call fails, the cached strip stays. A later successful page replaces the local rows. Opening a story still uses the current viewer; `show` writes the fresh row back when the network answers.

Queries return at most 200 stories, newest first.

## 4. Notifications local-first

The notification screen paints the cached page before `GET /notifications`. A failed refresh keeps that page. The list is capped at 100 rows. Tapping a notification still uses `openNotificationDeepLink` and the routes that already exist. If the payload has no destination, that helper does not invent one.

## 5. View tracking

The viewer already marks a story seen in memory and then calls `POST /stories/{id}/view`, ignoring a network failure. The same flag is now written to SQLite before the request, and the server story replaces the row when the request succeeds. A repeated open of the same story in one viewer session still does not send a second view. Mine stories are still not marked viewed.

## 6. Viewer caching

`GET /stories/{id}/viewers` is stored per story. If the request fails and viewers were cached, the sheet gets those names. It does not reload the whole story list.

## 7. Expiration

The local query hides a story when `expires_at` is in the past. That is a display filter, not a second source of truth. The next successful sync writes the server list back. There is no extra expiry timer.

## 8. Read and read-all

Mark-read updates the local row first, then `POST /notifications/{id}/read`. If that call fails, the local row is marked unread again.

Mark-all uses the existing `POST /notifications/read-all` once. SQLite is updated only after that call succeeds, so a failed request does not clear unread badges.

Preferences load from SQLite when a snapshot exists, then refresh from the server. Save writes the server response back into the snapshot.

## 9. Sync

No new sync engine. Stories refresh when the updates controller loads. Notifications refresh when the center opens or the user pulls to refresh. A loaded stories tab still skips a repeat fetch when `refresh(force: false)` is used. There is no new polling loop.

## 10. Workspace isolation

Every story, viewer, notification, and preference row is stored with `workspace_id`. Queries require that id. Logout `clearAll()` deletes these tables with the inbox and Phase C rows. Switching workspace changes the id the repositories read.

## 11. Offline behavior

A cached story strip, cached viewers, a cached notification list, and saved preference flags render without the network. Creating or deleting a story, sending a view that has not been stored yet, and saving preferences still need the server. Delete is applied locally only after the server accepts it, as a `deleted_at` flag rather than a hard erase.

## 12. Performance

500 story rows and 2,000 notification rows were inserted locally. The list queries returned 200 and 100 rows and finished in under 1.5 seconds. The UI does not load the full tables.

## 13. Migrations

Schema version is 6. v1 through v5 upgrades still run, then the feed tables are created with `IF NOT EXISTS`. Phase B conversations and the text outbox, and Phase C tables, are not rewritten.

## 14. Tests

`test/local_first_phase_d_test.dart` covers expiry filtering, workspace isolation, soft delete, viewers, notification read and read-all, secret stripping, preference snapshot, logout wipe of the new rows without leaving the inbox query broken, and the bounded 500/2000 query.

## 15. Limitations

- The first open with an empty cache still waits for the API.
- Story create and delete stay online.
- Signed media URLs are not cached, so an image or video may still download when opened.
- There is no `updated_since` feed for stories or notifications.
- One local view flag is not rolled back when the view request fails; that matches the viewer, which already treats tracking as best-effort.

## 16. Push delivery

Push registration is unchanged. This phase does not deliver FCM or APNs messages into SQLite. The notification center is local-first for rows that have already been fetched. Inbound push insertion remains an external gap.

---

## Phase D answers

- **Stories:** yes, when that workspace already has non-expired stories. The strip renders from SQLite, then refreshes. An empty cache still waits once.
- **Notifications:** yes, when that workspace already has a cached page. The center renders it before the refresh.
- **Workspace isolation:** yes. Stories, viewers, notifications, and preferences are queried by `workspace_id`. Logout clears them with the rest of the local session.
- **Still online-only:** creating a story, deleting a story until the server accepts it, the view request itself, saving notification preferences, and mark-all until `read-all` succeeds.
- **Phase B and C:** their local tests still pass, including the inbox outbox, products, contacts, and email tables.
- **`flutter analyze`:** no issues on the Phase D files.
- **Full Flutter suite:** **459 passed, 2 failed**. The failures are the two older cases: production host `http://127.0.0.1:8000`, and the inner chrome corner size.
- **Blocker:** none for the local notification center or the story strip. Push delivery into the local database is still not implemented, and that does not block the center once a fetch has been cached.
