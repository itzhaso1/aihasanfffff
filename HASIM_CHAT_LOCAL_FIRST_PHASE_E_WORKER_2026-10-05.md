# Hasim Chat — Local-First Phase E (Independent Worker)

**Date:** 2026-10-05  
**Scope:** Independent Worker read models on the existing Drift database.  
**Schema:** v6 → v7. No second database and no Laravel change.

Independent Worker stays outside `workspace_users`. Capabilities in SQLite are display data. Laravel still decides whether a worker may act.

---

## 1. Worker schema

`local_worker_records` stores the last server payload for one worker:

- `worker_profile_id`
- `resource` (`profile`, `engagements`, `products`, `sessions`, `earnings`, `payouts`, `disputes`, `documents`)
- `scope_id` (engagement id for products, sessions, and earnings; otherwise 0)
- `payload_json`
- `cached_at`

The primary key is `(worker_profile_id, resource, scope_id)`. Workspace id is not the owner of these rows.

## 2. Worker account

The account snapshot from Phase B already stores `participant_type` and `worker_profile_id` without a token. Auth now copies that profile id into `activeWorkerProfileIdProvider` so worker queries do not use company membership. Logout clears the id and deletes `local_worker_records` with the rest of the local session.

## 3. Profile

Profile, skills, languages, and experiences are the profile payload. The account screen shows the cached profile before `GET` profile, then replaces it from the server. A failed refresh keeps the cached profile.

## 4. Offers

Offers are engagements whose status is `offered`. They live in the engagements payload, not a second list. Accept and reject stay online. After the server accepts the change, that engagement is written back into the cached list. There is no offline accept or reject.

## 5. Engagements

The engagements screen paints the cached list, then refreshes `/worker/engagements`. Each row keeps its workspace id only as the company the engagement belongs to. The worker is not stored as a member of that workspace.

## 6. Capabilities

Capabilities stay inside the engagement payload (`capability_key`, display name). The UI can show them offline. They do not grant permission. The server still rejects an action the worker is not allowed to perform.

## 7. Guides

The guide is part of the engagement payload (sections, steps, FAQ). Opening an engagement that was cached shows that guide without a separate download. The next successful engagement fetch replaces it.

## 8. Assigned products

`GET /worker/engagements/{id}/products` is cached per engagement. The stored fields are the ones the product card already uses: id, name, image reference, price range, status. Digital codes are not part of that payload and are not added.

## 9. Sessions

The last session list for an engagement is cached for display, including `started_at` and status. Start, break, resume, and end still call Laravel and do not run from the outbox. Flutter still does not compute duration.

## 10. Earnings

Earning periods returned by the worker endpoint are cached per engagement. Draft periods stay hidden the same way as before. Amounts are the server minor units. The app does not calculate pay.

## 11. Payouts

The payout list is cached for display. There is no payout request or bank-detail write in this phase, and no payment secret is stored.

## 12. Disputes

The default first page of disputes is cached. Filtered pages are not mixed into that cache. Creating a dispute stays online. The local list updates on the next successful fetch.

## 13. Worker Inbox

The worker still uses the canonical inbox from Phase B. This phase does not add a second conversation store or a worker-only inbox. Engagement workspace selection remains the existing worker inbox controller. Worker profile rows are not written into `local_workspaces` as memberships.

## 14. Sync

No new sync engine and no outbox operations. Each screen reads SQLite, then performs the same bounded request it already used. There is no worker change feed, so refresh is still the last list the API returned, not a full history download.

## 15. Offline behavior

With a prior successful fetch, profile, engagements (including offers and guides), assigned products, sessions, earnings, payouts, disputes, and document metadata render without waiting. Accept, reject, session changes, verification upload, identity save, and dispute create still need the network. If the refresh fails, the cached rows stay.

## 16. Workspace and engagement isolation

Worker A cannot read worker B’s rows. Products, sessions, and earnings for engagement 10 are not returned for engagement 11. Company inbox data remains keyed by `workspace_id` on the other tables. Clearing a company workspace does not delete the worker record table.

## 17. Security

SQLite does not store the access token, password, document bytes, or identity number. Identity (`legal_name`, date of birth, identity number, address) is still loaded from the API only and is not written into `local_worker_records`. Document cache is type, filename, size, status, and rejection reason.

## 18. Migrations

Version 7 adds `local_worker_records` with `CREATE TABLE IF NOT EXISTS`. Versions 1–6 still upgrade in place. Logout deletes the new table together with Phase B, C, and D rows.

## 19. Tests

`test/local_first_phase_e_test.dart` covers two workers, two engagements, profile skills and experiences, the absence of a token column, and logout clearing worker rows and inbox rows together.

## 20. Performance

The cache stores the last fetched list, which the mobile API already bounds. Reading one worker’s engagements or one engagement’s products is a single primary-key lookup, not a scan of every worker.

## 21. Limitations

- The first open with an empty cache still waits for the API.
- Identity data is not available offline.
- Accept, reject, sessions, verification upload, and dispute create are online-only.
- There is no incremental worker feed. A refresh replaces the last cached list for that resource.
- One compose of filtered disputes is not reused as the unfiltered cache.

## 22. Required future contracts

- `updated_since` for engagements, earnings, and disputes if the history grows past one page
- a version field before any of the online mutations move into the outbox
- an explicit decision if identity should ever be cached, with encryption rather than plain SQLite

---

## Phase E answers

- **Worker data:** yes, after one successful fetch. Profile, engagements, products, sessions, earnings, payouts, disputes, and document metadata render from SQLite, then refresh. The first launch still waits for the API.
- **Offers and engagements:** yes. Offers are the `offered` rows inside the cached engagement list. Guides travel with that payload.
- **Worker inbox:** yes, it is still the canonical inbox. This phase did not add a second inbox or treat the worker as a workspace member.
- **Still online-only:** accept and reject offers, start/break/resume/end session, identity read and save, document upload, verification submit, and creating a dispute.
- **Company and worker data:** separate keys. Company rows use `workspace_id`. Worker rows use `worker_profile_id`, plus `engagement_id` when the resource belongs to one engagement.
- **Phases B, C, and D:** their tables and the text outbox stay on the same database. Logout still clears them together.
- **`flutter analyze`:** no issues on the Phase E files after the unused-import fix.
- **Full Flutter suite:** **462 passed, 2 failed**. The failures are the two older cases: production host `http://127.0.0.1:8000`, and the inner chrome corner size.
- **Blocker:** none for local worker reads. Offline lifecycle changes still need a versioned server contract before they can enter the outbox.
