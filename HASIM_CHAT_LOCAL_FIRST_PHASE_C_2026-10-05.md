# Hasim Chat — Local-First Phase C

**Date:** 2026-10-05  
**Scope:** products, variants, categories, digital metadata, contacts, contact groups, email list/detail, compose drafts, and campaign metadata.  
**Database:** the same Drift file, schema **v4 → v5**. No second database and no Laravel change.

Independent Worker stays outside `workspace_users`. Stories, channels, AI, worker, notifications, and commerce were not changed.

---

## 1. Database schema changes

`InboxDatabase.schemaVersion` is **5**. Fresh installs create the new tables. An existing database runs `_createDomainTables` on upgrade. Phase B conversations, messages, and outbox rows stay.

New tables, all keyed by `workspace_id` + server id except the compose draft:

| Table | Holds |
| --- | --- |
| `local_catalog_items` | Product read model, including price text, worker min/max, category id, image URL |
| `local_catalog_variants` | Variant rows linked by `product_server_id` |
| `local_catalog_categories` | Name, parent, sort, visibility |
| `local_digital_pools` | Sanitized pool JSON |
| `local_digital_units` | Type, status, expiry, label only |
| `local_contacts` | Contact fields plus `search_text` |
| `local_contact_groups` | Group name and count |
| `local_contact_group_members` | Group ↔ contact |
| `local_emails` | Folder, headers, preview, optional body, read/star |
| `local_email_drafts` | One compose draft per workspace |
| `local_email_campaigns` | Status metadata for a campaign the app has seen |

Indexes cover product name, variant product, category parent, contact name, and email folder + time.

Logout `clearAll()` and workspace `clearWorkspace()` delete these rows with the inbox data.

## 2. Products local-first

`ChatCatalogRepository` writes each successful list or detail page into SQLite and, if the request fails, returns the cached page. The product list paints cached rows before the network call and does not replace them with an error when cache exists.

Search uses `lower(name)` / `lower(sku)` with a bound `LIMIT`, not an unbounded scan.

## 3. Variants and categories

Variants are replaced for a product when the payload includes them, so a later detail response does not drop worker prices or the category id on the parent row. Categories use the same cache-then-refresh path. Create, update, and delete stay online; the local row is written only after the server accepts the call.

## 4. Contacts local-first

The contacts list reads SQLite first, then refreshes `GET /contacts`. Search and the favorite filter run in SQL. Create, update, and delete stay online and then reconcile the local row. A failed refresh keeps the list that is already on screen.

## 5. Contact groups

Group list, create, update, member sync, and delete follow the same server-then-reconcile rule. Member links are stored only from the contact ids the server returns. There is no second group store.

## 6. Email local-first

Inbox, sent, and drafts are separate `folder` values in `local_emails`. They are not chat messages. The email controller watches the local query and refreshes the current folder in the background. Opening a message shows the cached row immediately, then `GET /emails/{id}` fills the body when the network answers.

## 7. Drafts

There is no mobile endpoint that saves a compose draft separately from send. The compose screen keeps one local draft per workspace (recipients, subject, body). Leaving the screen saves it. A successful send or campaign create deletes it. Replies do not overwrite that draft. This is device state, not a server draft, so two devices do not merge it.

The server drafts folder (`GET /emails/drafts`) is still the list of server drafts and is cached like the inbox.

## 8. Attachments metadata

Email and digital payloads are not stored as bytes. Digital pool JSON drops keys named secret, code, token, credential, license, and pin, including nested maps. Unit rows keep type, status, expiry, and label. Import, replace-secret, reserve, consume, and delete stay online and do not write those secrets into SQLite.

## 9. Outbox changes

The text-message outbox was not extended. Product writes, contact writes, inventory, digital stock, email send, and campaign create/cancel are server-authoritative. Putting them in the outbox without a version field would hide conflicts. Read/star updates the local flag first and rolls it back if the API rejects the call.

## 10. Sync behavior

Same pattern as Phase B: read SQLite, render, then one bounded page refresh. There is still no `updated_since` feed. A failed first page returns the cached rows and sets `lastReadFromCache` where the repository already exposes it. Pagination cursors are unchanged.

## 11. Workspace isolation

Every new query takes `workspace_id`. Workspace A rows are not returned for workspace B. Pending text outbox rows from Phase B are untouched. Logout deletes the new tables with the rest of the local session.

## 12. Offline behavior

With cached data, product list, contact list, contact search, email folders, and a previously opened email render without waiting. Compose shows the local draft. If the refresh fails, the cached rows stay. Mutations listed in section 9 still need the network.

## 13. Conflict and reconciliation

Products, contacts, groups, email, and campaigns are server-authoritative. The server body replaces the local row after a successful write. There is no version column on these mobile resources, so this phase does not guess last-write-wins for offline edits. That limitation is why those writes stayed online.

## 14. Performance

List queries are limited (500 products/contacts, 200 emails per folder). A local test stored 500 products and found one by name in under 1.5 seconds. Email and contact searches filter in SQL with `LIMIT`.

## 15. Tests

`test/local_first_phase_c_test.dart` covers v1→v5 upgrade with the outbox kept, product/variant/category isolation, digital secret stripping, contacts/groups/email folders, read/star flags, the compose draft, logout wipe, and the 500-product query.

## 16. Laravel changes

None.

## 17. Limitations

- First open of an empty cache still waits for the API.
- Product, contact, group, inventory, digital stock, send, and campaign execution are online-only.
- Compose draft is one per workspace and is not synced to the server.
- There is no campaign list endpoint, so only campaigns the app has created or opened are cached.
- Signed download URLs are not stored.
- No incremental change feed.

## 18. Required future sync contracts

- `updated_since` or a cursor of changes for catalog, contacts, and email
- tombstones for deleted products and contacts
- a real draft upsert endpoint if compose drafts must sync across devices
- idempotency on update/delete if those writes move into the outbox later

---

## Phase C answers

- **Products:** yes, when that workspace already has cached products. The list renders them before the refresh. An empty cache still waits once.
- **Contacts:** yes, on the same rule, including local search of the cached set.
- **Email:** yes for a folder that was cached. Detail opens from the cached row, then fills the body if it was not stored yet.
- **Drafts:** the compose draft is stored locally per workspace and restored when compose opens again. Server draft messages stay in the drafts folder cache.
- **Still online-only:** create/update/delete for products, categories, contacts, and groups; inventory and activation; every digital secret or stock action; sending mail; creating or cancelling a campaign.
- **Phase B:** conversations, messages, text outbox, account snapshot, and workspace isolation tests still pass. Logout still clears both the inbox and these new tables.
- **`flutter analyze`:** no issues on the Phase C files.
- **Full Flutter suite:** **455 passed, 2 failed**. The failures are the two older cases: production host `http://127.0.0.1:8000`, and the inner chrome corner size.
- **Blocker:** none that stops this phase. Offline product/contact edits need a versioned sync contract before they can enter the outbox safely.
