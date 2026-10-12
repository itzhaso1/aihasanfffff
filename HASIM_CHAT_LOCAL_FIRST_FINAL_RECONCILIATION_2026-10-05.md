# Hasim Chat — Local-First final reconciliation (Phase G)

**Date:** 2026-10-05  
**Scope:** Audit of Phases B–F, plus the small isolation and outbox fixes found during that audit.  
**Database:** the existing Drift file only. Schema stays at v8. No Laravel change.

Independent Worker remains outside `workspace_users`. The app still has one inbox. Authorization stays on the server.

---

## 1. Phases B–F

| Phase | What became a local read model |
| --- | --- |
| B | Account snapshot (no token), workspace list, conversations, newest message window, text outbox |
| C | Catalog items, variants, categories, digital metadata with secrets stripped, contacts, groups, email list/detail, one device compose draft, campaign metadata |
| D | Stories (expired rows hidden locally), notifications, notification preferences. Signed media URLs are not stored |
| E | Worker profile, engagements, products, work sessions, earnings, payouts, disputes, and document metadata. Keyed by `worker_profile_id` and, where the row belongs to one engagement, `scope_id`. Identity fields are not stored |
| F | Channel list, current plan, plan catalog, and device-session display fields. Channel and session secrets are stripped before write |

The API remains the source of truth. SQLite is the read model: paint the saved rows, then refresh. The first open with an empty cache waits for the network once.

## 2. Still server-confirmed

These stay online because there is no safe outbox contract, or because the mobile API is read-only:

- Archive, mute, classification, handoff, and assignment
- Catalog, contact, email, story, and notification writes, except the existing text send
- Email compose draft is device-only. There is no mobile draft-save route
- Worker accept, reject, work-session changes, verification upload, identity save, and dispute create
- Channel connect, disconnect, and Facebook page choice. A `web_only` channel still opens its manage URL
- Session revoke and push-device registration. The push token is not written to Drift
- Plan purchase and cancel. Mobile only has `GET /plan` and `GET /plans`
- Independent Worker registration. Company signup is already `POST /api/mobile/v1/auth/register`. Worker signup stays `/workers/register`
- Branding. `GET /branding` exists and no screen reads it
- AI settings and employees. Left unchanged

## 3. Drift

One database, schema v8. Tables are not duplicated per domain: inbox, domain, feed, worker, and company-settings tables each own their rows.

Upgrades run in order from v1 through v8 inside `beforeOpen`. Fresh databases create the current schema. Inbox queries that sort conversations and messages have workspace indexes. Company cache is keyed by `(workspace_id, resource)`. Worker rows are keyed by `(worker_profile_id, resource, scope_id)`.

No schema redesign in this phase.

## 4. Outbox

The only enqueued operation is `send_text`, with the client id used as the idempotency key. Phase G now refuses any other operation type and marks it failed instead of sending it as a message.

`flush` runs only for a known workspace. A missing workspace no longer selects every queued row. A flush for workspace B is skipped while the session is workspace A. Logout calls `clearAll`, which deletes the outbox with the rest of the local session. Login already clears the local database before the new session starts.

## 5. Isolation

Company workspace 4 cannot read workspace 9’s conversations, email, stories, or channels, and the reverse is true.

Worker 15 cannot read worker 16’s products. Engagement 10 cannot read engagement 11’s products for the same worker. A saved worker account stays `independent_worker` with an empty workspace list, so the local snapshot does not turn the worker into a company member.

Logout deletes worker rows, the account snapshot, and the outbox.

Workspace switch updates the session and prefs before the switch request returns. Conversations already dropped the old list at that moment. Phase G now does the same for the long-lived email and story state, and for catalog, contacts, notifications, plans, and sessions: the previous workspace’s rows are replaced by the new workspace’s cache, or cleared, before the refresh response is applied. An older email refresh cannot overwrite the newer workspace.

## 6. Channels and sessions

Channel cache drops `bot_token`, access and refresh tokens, client and app secrets, and any manage URL that contains `token`, `secret`, or `code=`. Session cache keeps id, name, device name, device type, last used, and the current flag. IP address, user agent, and token are not stored. Revoke stays online. Logout deletes `local_company_cache`.

## 7. Performance and UAT

The check is the Flutter suite, not a separate device pass. It covers:

- cold start from the account snapshot before `GET /auth/me` (Phase B)
- conversations, catalog, contacts, email, stories, notifications, worker reads, and channel/settings cache (Phases B–F)
- workspace switch clearing email and stories before the failed refresh returns (Phase G)
- outbox workspace scope and rejection of a non-text operation (Phase G)

No visual redesign. No second database, router, or state framework.

## 8. REST

Flutter paths used by these repositories match `routes/mobile.php`. No new endpoint was added. The accepted web handoffs are Independent Worker registration, subscription checkout, and `web_only` channel manage URLs.

Marketplace, storefront, cart, sellers, product templates, and a second worker inbox are not wired into the Hasim Chat router. The worker inbox control only chooses which engagement workspace the shared inbox uses.

## 9. Files changed in Phase G

- `apps/hasim/lib/core/local_db/outbox_worker.dart`
- `apps/hasim/lib/features/email/providers/email_controller.dart`
- `apps/hasim/lib/features/stories/providers/stories_controller.dart`
- `apps/hasim/lib/features/catalog/providers/chat_catalog_categories_controller.dart`
- `apps/hasim/lib/features/catalog/presentation/catalog_list_screen.dart`
- `apps/hasim/lib/features/contacts/presentation/contacts_list_screen.dart`
- `apps/hasim/lib/features/notifications/presentation/notifications_screen.dart`
- `apps/hasim/lib/features/settings/presentation/plans_screen.dart`
- `apps/hasim/lib/features/more/presentation/security_sessions_screen.dart`
- `apps/hasim/test/local_first_phase_g_test.dart`
- `apps/hasim/test/contacts_list_smoke_test.dart`

## 10. flutter analyze

`flutter analyze` in `apps/hasim`: **No issues found.**

## 11. Tests

Full Flutter suite: **469 passed, 2 failed.**

The two failures are the same pre-existing cases. This phase did not change them:

- `app_config_test.dart` — production host `http://127.0.0.1:8000` fails the HTTPS check
- `inner_chrome_corner_test.dart` — overlay size 5×4 instead of 4×4

No new failure.

## 12. Remaining blockers

None for Local-First. The two failures above are outside this work and are not blockers.
